<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Llm;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;
use Zwernemann\ConversationalCommerce\Api\LlmClientInterface;
use Zwernemann\ConversationalCommerce\Model\Attachment\AttachmentProcessor;
use Zwernemann\ConversationalCommerce\Model\Attachment\ExtractedAttachment;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;
use Zwernemann\ConversationalCommerce\Model\Notification\ErrorNotifier;
use Zwernemann\ConversationalCommerce\Model\Rag\ProductIndexer;

/**
 * Sends the full context to the configured LLM provider and interprets the structured response.
 *
 * Uses provider-native function/tool calling to guarantee valid JSON output —
 * no client-side JSON parsing fragility. The response schema is enforced by the API.
 */
class IntentProcessor
{
    private const TOOL_NAME = 'submit_response';

    private const TOOL_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'intent' => [
                'type'        => 'string',
                'enum'        => ['auto_reply', 'ask_clarification', 'other'],
                'description' => 'auto_reply: automatischer Out-of-Office-Responder — response_text/html leer lassen, keine Antwort wird gesendet. ask_clarification: LLM stellt eine Rückfrage ohne Tool-Ausführung. other: alle anderen Fälle inkl. Tool-Aufrufe.',
            ],
            'confidence' => ['type' => 'number'],
            'tool_calls' => [
                'type'        => 'array',
                'description' => 'Geordnete Liste von Magento-Aktionen die ausgeführt werden sollen. Leer lassen bei reinen Textantworten und Rückfragen.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'name'   => ['type' => 'string', 'description' => 'Tool-Name aus dem Katalog (z.B. cart_add_item, cart_checkout, get_order_history)'],
                        'params' => ['type' => 'object', 'description' => 'Tool-Parameter gemäß Katalog-Schema'],
                    ],
                    'required' => ['name', 'params'],
                ],
            ],
            'response_text' => [
                'type'        => 'string',
                'description' => 'Antwort im Klartext für E-Mail-Plaintext. Keine HTML-Tags.',
            ],
            'response_html' => [
                'type'        => 'string',
                'description' => 'Antwort als HTML für E-Mail-Body und WebChat. Pflicht: echte HTML-Tags verwenden — <ul><li> für Listen, <table><tr><td> für Produktübersichten ab 3 Positionen. Produktbilder als <img src="cid:product_ID"> einbetten. Niemals rohen Plaintext ohne HTML-Struktur ausgeben.',
            ],
            'product_ids_to_show' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'IDs of products to show images for, e.g. ["product_42","product_17"]',
            ],
        ],
        'required' => ['intent', 'confidence', 'tool_calls', 'response_text', 'response_html', 'product_ids_to_show'],
    ];

    public function __construct(
        private readonly LlmClientInterface     $llm,
        private readonly ContextBuilder         $contextBuilder,
        private readonly AttachmentProcessor    $attachmentProcessor,
        private readonly ProductIndexer         $productIndexer,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface  $storeManager,
        private readonly ErrorNotifier          $errorNotifier,
        private readonly LoggerInterface        $logger,
        private readonly PipelineLogger         $pipelineLogger
    ) {}

    /**
     * @param array<string, mixed>             $customerData
     * @param array<int, array<string, mixed>> $orderHistory
     * @param array<int, array<string, mixed>> $ragResults
     * @param array<int, array<string, mixed>> $conversationHistory
     * @param array<int, array{filename: string, content_type: string, data: string}> $rawAttachments
     * @return array<string, mixed>
     */
    /**
     * @param array<int, ExtractedAttachment> $preExtractedAttachments
     *   When non-empty, skips AttachmentProcessor::processAll() to avoid double-processing.
     *   Pass pre-extracted attachments from MessageProcessor (built before the RAG search).
     */
    public function process(
        UnifiedMessageInterface $message,
        array $customerData,
        array $orderHistory,
        array $ragResults,
        array $conversationHistory = [],
        array $rawAttachments = [],
        array $preExtractedAttachments = [],
        string $resolvedQuery = '',
        bool $degraded = false,
        string $queryType = 'product'
    ): array {
        $extractedAttachments = !empty($preExtractedAttachments)
            ? $preExtractedAttachments
            : $this->attachmentProcessor->processAll($rawAttachments);
        $documentBlocks       = $this->contextBuilder->buildDocumentBlocks($extractedAttachments);

        if (!empty($extractedAttachments)) {
            $this->pipelineLogger->section('ATTACHMENTS');
            $this->pipelineLogger->data('Processed attachments', array_map(
                fn($a) => ['file' => $a->getFilename(), 'type' => $a->getBlockType()],
                $extractedAttachments
            ));
        }

        $systemPrompt = $this->contextBuilder->buildSystemPrompt();
        $messages     = $this->contextBuilder->buildMessages(
            $message, $customerData, $orderHistory, $ragResults, $conversationHistory, $extractedAttachments, $resolvedQuery, $degraded, $queryType
        );

        try {
            $result = $this->llm->chatWithTool(
                $messages,
                $systemPrompt,
                self::TOOL_NAME,
                self::TOOL_SCHEMA,
                [],
                $documentBlocks
            );

            if (empty($result)) {
                $this->logger->error('ConversationalCommerce: chatWithTool returned empty result — using fallback.');
                return $this->fallbackResult('Empty tool_use response from LLM');
            }
        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: LLM processing failed – ' . $e->getMessage());
            $this->errorNotifier->notify('LLM-API-Fehler', $e->getMessage());
            return $this->fallbackResult($e->getMessage());
        }

        // Ensure required keys exist with safe defaults
        $result['tool_calls']          ??= [];
        $result['product_ids_to_show'] ??= [];

        $this->logger->info('[IntentProcessor] product_ids_to_show from LLM', [
            'count' => count($result['product_ids_to_show']),
            'ids'   => $result['product_ids_to_show'],
        ]);

        $this->pipelineLogger->section('LLM STRUCTURED RESULT (tool_use output)');
        $logResult = $result;
        unset($logResult['response_html']);
        $this->pipelineLogger->data('Parsed LLM result (without response_html)', $logResult);
        $this->logger->info('[IntentProcessor] tool_calls from LLM', [
            'count'      => count($result['tool_calls']),
            'tool_names' => array_column($result['tool_calls'], 'name'),
        ]);
        $this->pipelineLogger->raw('response_text (plain)', $result['response_text'] ?? '');
        $this->pipelineLogger->raw('response_html (before image injection)', $result['response_html'] ?? '');

        // Enrich HTML with inline product images
        $inlineImages = [];
        $html = $result['response_html'] ?? $result['response_text'] ?? '';

        foreach ($result['product_ids_to_show'] as $productKey) {
            $this->logger->info('[IntentProcessor] Processing product image', [
                'key'           => $productKey,
                'rag_item_count'=> count($ragResults),
                'rag_pids'      => array_map(
                    fn($r) => 'product_' . ($r['metadata']['product_id'] ?? '?'),
                    array_slice($ragResults, 0, 10)
                ),
            ]);

            foreach ($ragResults as $ragItem) {
                $meta = $ragItem['metadata'] ?? [];
                $pid  = 'product_' . ($meta['product_id'] ?? '');
                if ($pid !== $productKey) {
                    continue;
                }

                // Use image_url from Pinecone metadata; fall back to live Magento catalog lookup
                $imageUrl = $meta['image_url'] ?? null;
                $this->logger->info('[IntentProcessor] RAG metadata for ' . $productKey, [
                    'sku'       => $meta['sku'] ?? '?',
                    'image_url' => $imageUrl ?? '(empty)',
                ]);
                if (!$imageUrl) {
                    $imageUrl = $this->resolveImageUrlFromCatalog($meta['sku'] ?? '');
                    if ($imageUrl) {
                        $this->logger->info('[IntentProcessor] Resolved image URL from catalog for ' . $productKey
                            . ' (Pinecone metadata missing image_url — re-index to cache it).');
                    } else {
                        $this->logger->warning('[IntentProcessor] No image available for ' . $productKey
                            . ' (SKU: ' . ($meta['sku'] ?? '?') . '). Removing broken img tag from HTML.');
                        // Remove the broken <img> tag so the email client shows nothing instead of alt text
                        $html = preg_replace(
                            '/<img[^>]+src=["\']cid:' . preg_quote($productKey, '/') . '["\'][^>]*>/i',
                            '',
                            $html
                        );
                        break;
                    }
                }

                // CID matches what the LLM outputs: cid:product_42
                $cid       = 'product_' . ($meta['product_id'] ?? md5($productKey));
                $imageData = $this->fetchImageAsBase64($imageUrl);
                if ($imageData) {
                    $inlineImages[$cid] = [
                        'cid'  => $cid,
                        'data' => $imageData['data'],
                        'mime' => $imageData['mime'],
                    ];
                    // Replace raw image URL in HTML if LLM happened to include it
                    $html = str_replace($imageUrl, 'cid:' . $cid, $html);
                    // Inject card only if the LLM did NOT already place the cid: reference
                    if (!str_contains($html, 'cid:' . $cid)) {
                        $name   = htmlspecialchars($meta['name'] ?? $productKey);
                        $priceF = number_format((float)($meta['price'] ?? 0), 2, ',', '.');
                        $html  .= sprintf(
                            '<div style="margin:10px 0;padding:10px;border:1px solid #eee;overflow:hidden;">'
                            . '<img src="cid:%s" alt="%s" width="200" style="max-width:200px;float:left;margin-right:10px;">'
                            . '<strong>%s</strong><br>SKU: %s<br>Preis: %s EUR</div>',
                            $cid, $name, $name, htmlspecialchars($meta['sku'] ?? ''), $priceF
                        );
                    }
                } else {
                    // Fetch failed — remove broken img tag
                    $html = preg_replace(
                        '/<img[^>]+src=["\']cid:' . preg_quote($cid, '/') . '["\'][^>]*>/i',
                        '',
                        $html
                    );
                }
                break;
            }
        }

        // Ensure all CID img tags have explicit width="200" so Outlook/email clients
        // don't render the full original image dimensions
        $html = preg_replace_callback(
            '/<img([^>]+src=["\']cid:[^"\']+["\'][^>]*)>/i',
            static function (array $m): string {
                $attrs = $m[1];
                if (!preg_match('/\bwidth\s*=/i', $attrs)) {
                    $attrs .= ' width="200"';
                }
                // Inject or extend style to cap height too
                if (preg_match('/\bstyle\s*=\s*["\']([^"\']*)["\']/', $attrs, $s)) {
                    $style = $s[1];
                    if (!str_contains($style, 'max-width')) {
                        $style .= ';max-width:200px';
                    }
                    $attrs = preg_replace('/\bstyle\s*=\s*["\'][^"\']*["\']/', 'style="' . $style . '"', $attrs);
                } else {
                    $attrs .= ' style="max-width:200px"';
                }
                return '<img' . $attrs . '>';
            },
            $html
        ) ?? $html;

        $result['response_html'] = $html;
        $result['inline_images'] = $inlineImages;
        return $result;
    }

    /**
     * Fetch the product image URL from the Magento catalog by SKU.
     * Used as fallback when image_url is missing from Pinecone metadata (old vectors).
     */
    private function resolveImageUrlFromCatalog(string $sku): ?string
    {
        if ($sku === '') {
            return null;
        }
        try {
            $product = $this->productRepository->get($sku);
            $image   = $product->getImage();
            if (!$image || $image === 'no_selection') {
                return null;
            }
            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            return rtrim($mediaUrl, '/') . '/catalog/product' . $image;
        } catch (\Throwable $e) {
            $this->logger->warning('[IntentProcessor] Catalog image lookup failed for SKU ' . $sku . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * If $url points to this store's own media directory, read from the local
     * filesystem instead of making an HTTP request. This avoids authentication
     * issues with Codespaces port-forwarding and is faster in all environments.
     *
     * @return array{data: string, mime: string}|null
     */
    private function tryReadFromLocalMedia(string $url): ?array
    {
        try {
            $mediaBaseUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
            if (!str_starts_with($url, $mediaBaseUrl)) {
                return null;
            }
            $relativePath = substr($url, strlen($mediaBaseUrl));
            $localPath    = BP . '/pub/media/' . ltrim($relativePath, '/');
            if (!is_file($localPath) || !is_readable($localPath)) {
                return null;
            }
            $data = file_get_contents($localPath);
            if ($data === false || strlen($data) < 100) {
                return null;
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->buffer($data) ?: 'image/jpeg';
            $this->logger->info('[IntentProcessor] Image read from local filesystem', [
                'path'  => $localPath,
                'bytes' => strlen($data),
                'mime'  => $mime,
            ]);
            return ['data' => base64_encode($data), 'mime' => $mime];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{data: string, mime: string}|null */
    private function fetchImageAsBase64(string $url): ?array
    {
        // Fast path: read directly from disk for local media URLs
        $localData = $this->tryReadFromLocalMedia($url);
        if ($localData !== null) {
            return $localData;
        }

        try {
            $opts = [
                'http' => [
                    'timeout'       => 10,
                    'ignore_errors' => true,
                    'user_agent'    => 'ConversationalCommerce/1.0',
                ],
                'ssl'  => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ];
            $context = stream_context_create($opts);

            $lastError = null;
            set_error_handler(static function (int $no, string $str) use (&$lastError): bool {
                $lastError = $str;
                return true;
            });
            $data = file_get_contents($url, false, $context);
            restore_error_handler();

            if ($data === false) {
                $this->logger->warning('[IntentProcessor] Image fetch failed', [
                    'url'   => $url,
                    'error' => $lastError ?? 'unknown',
                ]);
                return null;
            }

            $bytes = strlen($data);
            if ($bytes < 100) {
                $this->logger->warning('[IntentProcessor] Image fetch returned too little data', [
                    'url'   => $url,
                    'bytes' => $bytes,
                    'data'  => substr($data, 0, 200),
                ]);
                return null;
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->buffer($data) ?: 'image/jpeg';
            $this->logger->info('[IntentProcessor] Image fetched successfully', [
                'url'   => $url,
                'bytes' => $bytes,
                'mime'  => $mime,
            ]);
            return ['data' => base64_encode($data), 'mime' => $mime];
        } catch (\Throwable $e) {
            $this->logger->warning('[IntentProcessor] Image fetch exception', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fallbackResult(string $errorMsg): array
    {
        $text = 'Es tut mir leid, Ihre Anfrage konnte momentan nicht verarbeitet werden. '
              . 'Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.';
        return [
            'intent'              => 'other',
            'confidence'          => 0.0,
            'tool_calls'          => [],
            'response_text'       => $text,
            'response_html'       => '<p>' . htmlspecialchars($text) . '</p>',
            'product_ids_to_show' => [],
            'inline_images'       => [],
            '_error'              => $errorMsg,
        ];
    }
}
