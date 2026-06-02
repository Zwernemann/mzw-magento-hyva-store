<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Rag;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\Llm\AnthropicClient;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;

/**
 * Extracts product-relevant search terms from a free-form customer message
 * using a tiny Haiku call with forced tool_use output.
 *
 * Falls back to the original query as a single term if extraction fails,
 * so the keyword search always has something to work with.
 */
class SearchTermExtractor
{
    private const XML_CATALOG_LANG = 'conversional_commerce/voyage/catalog_language';

    private const SYSTEM = 'Extract product names, model numbers, SKUs, brands, and categories from the message. '
        . 'For each term include both singular and plural forms (e.g. for "Tassen" add both "Tasse" and "Tassen", '
        . 'for "mugs" add both "mug" and "mugs"). '
        . 'Short, specific terms. Return [] if no products or categories are mentioned.';

    private const TOOL_NAME   = 'return_search_terms';
    private const TOOL_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'terms' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Product-relevant search terms extracted from the message',
            ],
        ],
        'required' => ['terms'],
    ];

    private bool $degraded = false;

    public function __construct(
        private readonly AnthropicClient    $claude,
        private readonly LoggerInterface    $logger,
        private readonly PipelineLogger     $pipelineLogger,
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * @return string[]  Product-relevant search terms, or the original query as fallback
     */
    public function extract(string $query, int $storeId = 0): array
    {
        $this->degraded = false;

        $lang = trim((string)($this->scopeConfig->getValue(
            self::XML_CATALOG_LANG,
            $storeId > 0 ? ScopeInterface::SCOPE_STORE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $storeId > 0 ? $storeId : null
        ) ?? ''));

        $system = self::SYSTEM;
        if ($lang !== '') {
            $system .= ' The product catalog is in ' . $lang . '.'
                . ' Include ' . $lang . ' synonyms or translations for any non-' . $lang . ' terms.';
        }

        $this->pipelineLogger->section('SEARCH TERM EXTRACTION (Haiku)');
        $this->pipelineLogger->raw('Input query', $query);
        $this->pipelineLogger->raw('System prompt', $system);

        try {
            $raw = $this->claude->chatWithTool(
                [['role' => 'user', 'content' => $query]],
                $system,
                self::TOOL_NAME,
                self::TOOL_SCHEMA,
                ['model' => 'claude-haiku-4-5-20251001', 'max_tokens' => 150]
            );

            $terms = array_values(array_filter(
                is_array($raw['terms'] ?? null) ? $raw['terms'] : [],
                'is_string'
            ));

            // Strip common prefixes that customers write before SKU codes,
            // e.g. "SKU 08-0074" → "08-0074", "Art.Nr. 08-0074" → "08-0074"
            $terms = array_map(static function (string $t): string {
                return preg_replace(
                    '/^\s*(?:sku|art(?:ikel)?(?:[\-\.]?nr\.?)?|ref(?:[\-\.]?nr\.?)?|bestellnummer|pos\.?)\s*/ui',
                    '',
                    trim($t)
                );
            }, $terms);
            $terms = array_values(array_filter($terms));

        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'verload')) {
                $this->degraded = true;
            }
            $this->logger->warning('[SearchTermExtractor] failed – ' . $e->getMessage());
            $terms = [];
        }

        // Fallback: use the original query verbatim so keyword search is never empty
        if (empty($terms)) {
            $terms = [mb_substr($query, 0, 200)];
        }

        $this->pipelineLogger->data('Extracted terms (after SKU prefix strip)', $terms);

        $this->logger->info('[SearchTermExtractor] extracted', [
            'query' => mb_substr($query, 0, 200),
            'terms' => $terms,
        ]);

        return $terms;
    }
}
