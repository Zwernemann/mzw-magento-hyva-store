<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;
use Zwernemann\ConversationalCommerce\Api\Data\ConversationInterface;
use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;
use Zwernemann\ConversationalCommerce\Api\MessageProcessorInterface;
use Zwernemann\ConversationalCommerce\Api\ChannelInterface;
use Zwernemann\ConversationalCommerce\Api\Shop\CartServiceInterface;
use Zwernemann\ConversationalCommerce\Api\Shop\CustomerProviderInterface;
use Zwernemann\ConversationalCommerce\Api\Shop\OrderHistoryInterface;
use Zwernemann\ConversationalCommerce\Api\Shop\ProductLookupInterface;
use Zwernemann\ConversationalCommerce\Model\Attachment\AttachmentProcessor;
use Zwernemann\ConversationalCommerce\Model\Channel\Email\EmailChannel;
use Zwernemann\ConversationalCommerce\Model\Llm\ContextBuilder;
use Zwernemann\ConversationalCommerce\Model\Llm\IntentProcessor;
use Zwernemann\ConversationalCommerce\Model\Escalation\EscalationDetector;
use Zwernemann\ConversationalCommerce\Model\Escalation\EscalationService;
use Zwernemann\ConversationalCommerce\Model\Magento\PaymentInfoProvider;
use Zwernemann\ConversationalCommerce\Model\Pipeline\CartActionHandler;
use Zwernemann\ConversationalCommerce\Model\Pipeline\MagentoToolExecutor;
use Zwernemann\ConversationalCommerce\Model\Notification\ErrorNotifier;
use Zwernemann\ConversationalCommerce\Model\Rag\ConversationalQueryBuilder;
use Zwernemann\ConversationalCommerce\Model\Rag\ProductIndexer;

/**
 * Central orchestrator for incoming messages.
 *
 * Processing pipeline:
 * 1. Resolve customer (Magento REST)
 * 2. Load order history (Magento REST)
 * 3. Semantic product search (Pinecone + Voyage RAG)
 * 4. LLM intent detection + response generation (Anthropic Claude)
 * 5a. Create order if intent = order/reorder (Magento REST)
 * 5b. Ask clarification if intent = clarification
 * 6. Send response email with inline product images
 * 7. Persist conversation + messages in DB
 */
class MessageProcessor implements MessageProcessorInterface
{
    /** @var ChannelInterface[] */
    private readonly array $channels;

    public function __construct(
        private readonly CustomerProviderInterface $customerLookup,
        private readonly OrderHistoryInterface     $orderHistory,
        private readonly ProductIndexer            $productIndexer,
        private readonly ProductLookupInterface    $productSearch,
        private readonly ContextBuilder            $contextBuilder,
        private readonly IntentProcessor           $intentProcessor,
        private readonly AttachmentProcessor       $attachmentProcessor,
        private readonly CartServiceInterface      $cartManager,
        private readonly CartActionHandler         $cartActionHandler,
        private readonly MagentoToolExecutor       $toolExecutor,
        private readonly PaymentInfoProvider       $paymentInfoProvider,
        private readonly EmailChannel              $emailChannel,
        private readonly ConversationFactory        $conversationFactory,
        private readonly ConversationMessageFactory $messageFactory,
        private readonly ResourceModel\Conversation $conversationResource,
        private readonly ResourceModel\ConversationMessage $messageResource,
        private readonly ConversationalQueryBuilder $queryBuilder,
        private readonly EscalationDetector    $escalationDetector,
        private readonly EscalationService     $escalationService,
        private readonly ErrorNotifier         $errorNotifier,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface      $logger,
        private readonly PipelineLogger       $pipelineLogger,
        array $channels = []
    ) {
        $this->channels = $channels;
    }

    public function process(UnifiedMessageInterface $message): array
    {
        $sid   = $message->getSessionId();
        $start = microtime(true);

        $this->pipelineLogger->enableDebugCapture(
            $message->getChannelType() === 'webchat'
            && $this->scopeConfig->isSetFlag(
                'conversional_commerce/webchat/debug_llm',
                ScopeInterface::SCOPE_STORE
            )
        );
        $this->pipelineLogger->startRequest($sid, $message->getMessageId(), $message->getChannelType());
        $this->pipelineLogger->section('INBOUND MESSAGE');
        $this->pipelineLogger->data('Metadata', [
            'channel'    => $message->getChannelType(),
            'from'       => $message->getCustomerIdentifier(),
            'session_id' => $sid,
            'message_id' => $message->getMessageId(),
        ]);
        $this->pipelineLogger->raw('Message body (full)', $message->getContentText());

        $this->logger->info('=== PIPELINE START ===', [
            'session'       => $sid,
            'channel'       => $message->getChannelType(),
            'from'          => $message->getCustomerIdentifier(),
            'subject'       => $message->getReplyTo()['subject'] ?? '',
            'body_chars'    => strlen($message->getContentText()),
            'body_preview'  => mb_substr($message->getContentText(), 0, 300),
        ]);

        // Step 1: Resolve customer — reject unregistered senders immediately
        $customerData = $this->resolveCustomer($message);
        if ($customerData === null) {
            $this->logger->warning('[STEP 1] Auth rejected — sender not a registered customer', [
                'session' => $sid,
                'email'   => $message->getCustomerIdentifier(),
            ]);
            $this->sendUnauthorizedReply($message);
            return 'unauthorized';
        }

        $this->logger->info('[STEP 1] Customer resolved', [
            'session'     => $sid,
            'customer_id' => $customerData['id'],
            'name'        => ($customerData['firstname'] ?? '') . ' ' . ($customerData['lastname'] ?? ''),
            'addresses'   => count($customerData['addresses'] ?? []),
        ]);

        // Step 1b: Auto-reply via RFC header — abort before LLM to prevent reply loops
        if ($message->isAutoReply()) {
            $this->logger->info('[STEP 1b] Auto-reply (header) detected — suppressing response to prevent loop', [
                'session' => $sid,
                'from'    => $message->getCustomerIdentifier(),
                'subject' => $message->getReplyTo()['subject'] ?? '',
            ]);
            $conversation = $this->getOrCreateConversation($message, $customerData);
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                'intent' => 'auto_reply',
            ]);
            return 'auto_reply';
        }

        // Step 2: Conversation session
        $conversation = $this->getOrCreateConversation($message, $customerData);
        $this->logger->info('[STEP 2] Conversation', [
            'session'         => $sid,
            'conversation_id' => $conversation->getId(),
            'status'          => $conversation->getStatus(),
        ]);

        // Step 2b: If the conversation is currently escalated, hold the message and
        // inform the customer — do not forward to the LLM.
        if ($conversation->getStatus() === ConversationInterface::STATUS_ESCALATED) {
            $this->logger->info('[STEP 2b] Conversation is escalated — holding inbound message', [
                'session'         => $sid,
                'conversation_id' => $conversation->getId(),
            ]);
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                'intent' => 'escalated_hold',
            ]);
            $holdText = "Vielen Dank für Ihre Nachricht.\n\n"
                . "Ihre Anfrage wird derzeit von unserem Team überprüft. "
                . "Sobald die Konversation freigegeben ist, erhalten Sie eine Antwort. "
                . "Bitte haben Sie noch etwas Geduld.";
            $holdHtml = '<p>Vielen Dank für Ihre Nachricht.</p>'
                . '<p>Ihre Anfrage wird derzeit von unserem Team überprüft. '
                . 'Sobald die Konversation freigegeben ist, erhalten Sie eine Antwort. '
                . 'Bitte haben Sie noch etwas Geduld.</p>';
            try {
                $channel = $this->channels[$message->getChannelType()] ?? $this->emailChannel;
                $channel->sendResponse($message, $holdText, $holdHtml);
            } catch (\Throwable $e) {
                $this->logger->warning('[STEP 2b] Failed to send escalation hold reply – ' . $e->getMessage());
            }
            return ['text' => $holdText, 'html' => $holdHtml];
        }

        // Step 2c: For email/WhatsApp, the channel carries no store context.
        // Derive the store from the customer's "Associate to Website" setting.
        if ($message->getChannelType() !== 'webchat' && !empty($customerData['website_id'])) {
            try {
                $website    = $this->storeManager->getWebsite((int)$customerData['website_id']);
                $group      = $this->storeManager->getGroup($website->getDefaultGroupId());
                $resolvedId = (int)$group->getDefaultStoreId();
                if ($resolvedId > 0 && $resolvedId !== (int)$conversation->getStoreId()) {
                    $conversation->setStoreId($resolvedId);
                    $this->conversationResource->save($conversation);
                    $this->logger->info('[STEP 2c] Store resolved from customer website', [
                        'session'    => $sid,
                        'website_id' => $customerData['website_id'],
                        'store_id'   => $resolvedId,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[STEP 2c] Could not resolve store from customer website_id=' . ($customerData['website_id'] ?? 0));
            }
        }

        $storeId         = (int)$conversation->getStoreId();
        $originalStoreId = (int)$this->storeManager->getStore()->getId();
        if ($storeId > 0) {
            $this->storeManager->setCurrentStore($storeId);
        }

        try {
            // Step 3: Order history
            $orders = $this->orderHistory->getByCustomerEmail(
                $message->getCustomerIdentifier(), 20, $storeId
            );
            $this->logger->info('[STEP 3] Order history', [
                'session'     => $sid,
                'order_count' => count($orders),
                'last_order'  => $orders[0]['increment_id'] ?? null,
                'last_total'  => isset($orders[0]) ? $orders[0]['grand_total'] . ' EUR' : null,
                'last_status' => $orders[0]['status'] ?? null,
            ]);

            // Pre-process attachments BEFORE the RAG search so that SKUs/product names
            // embedded in Excel/PDF/Word files are included in the search query.
            // This fixes the case where the message body is just "möchte das bestellen"
            // while the actual product identifiers live inside the attachment.
            $attachments          = $message->getAttachments();
            $extractedAttachments = [];
            if (!empty($attachments)) {
                try {
                    $extractedAttachments = $this->attachmentProcessor->processAll($attachments);
                } catch (\Throwable $e) {
                    $this->logger->warning('[STEP 4] Attachment pre-extraction failed', [
                        'session' => $sid,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // Build augmented search query: message body + plain text from each attachment
            $attachmentTexts = array_filter(array_map(
                fn($a) => $a->toSearchText(),
                $extractedAttachments
            ));
            $searchQuery = trim($message->getContentText()
                . (empty($attachmentTexts) ? '' : "\n" . implode("\n", $attachmentTexts)));

            // Use conversation-aware query builder to reformulate the search query.
            // For short continuation messages ("ja bitte", "10 stück") this resolves
            // the actual product from the recent conversation history.
            // Load recent history early (before step 5) specifically for this purpose.
            $recentHistoryForQuery = $this->messageResource->getMessagesByConversationId(
                (int)$conversation->getId(), $this->queryBuilder->getHistoryLoadCount(), 'DESC'
            );
            $queryBuilderResult  = $this->queryBuilder->build(
                trim($message->getContentText()), $recentHistoryForQuery
            );
            $queryType           = $queryBuilderResult['query_type'];
            $needsRag            = $queryBuilderResult['needs_rag'];
            $conversationalQuery = $queryBuilderResult['query'];

            if ($needsRag && $conversationalQuery !== trim($message->getContentText())) {
                $searchQuery = $conversationalQuery;
                if (!empty($attachmentTexts)) {
                    $searchQuery .= "\n" . implode("\n", $attachmentTexts);
                }
            }

            // Pass the resolved query to the LLM only when it differs from the raw message.
            $resolvedQueryForLlm = ($needsRag && $conversationalQuery !== trim($message->getContentText()))
                ? $conversationalQuery
                : '';

            // Step 4: Semantic product search — skip entirely for account/order/address queries
            $topK = max(1, (int)($this->scopeConfig->getValue('conversional_commerce/pinecone/top_k') ?: 10));
            $ragResults = [];
            if ($needsRag) {
                try {
                    $ragResults = $this->productIndexer->search($searchQuery, $topK, $storeId);
                } catch (\Throwable $e) {
                    $this->logger->warning('[STEP 4] RAG search failed', [
                        'session' => $sid,
                        'error'   => $e->getMessage(),
                    ]);
                    $this->errorNotifier->notify('RAG-Suchfehler (Pinecone/Voyage)', $e->getMessage(), $storeId);
                }
            }
            $this->logger->info('[STEP 4] RAG search', [
                'session'          => $sid,
                'query'            => $needsRag ? mb_substr($searchQuery, 0, 300) : '(skipped — non-product query)',
                'attachment_texts' => count($attachmentTexts),
                'hits'             => count($ragResults),
                'top_results'      => array_map(fn($r) => [
                    'name'      => $r['metadata']['name'] ?? '?',
                    'sku'       => $r['metadata']['sku']  ?? '?',
                    'cats'      => $r['metadata']['categories'] ?? '',
                    'score'     => round((float)($r['score'] ?? 0), 4),
                    'has_image' => !empty($r['metadata']['image_url']),
                ], $ragResults),
            ]);

            // Step 4b: Enrich RAG results with live stock data from Magento
            if (!empty($ragResults)) {
                $skus = array_values(array_filter(array_map(
                    fn($r) => $r['metadata']['sku'] ?? '',
                    $ragResults
                )));
                if (!empty($skus)) {
                    $stockData = $this->productSearch->getStockForSkus($skus);
                    foreach ($ragResults as &$result) {
                        $sku = $result['metadata']['sku'] ?? '';
                        if ($sku && isset($stockData[$sku])) {
                            $sd = $stockData[$sku];
                            $result['metadata']['in_stock']     = $sd['in_stock'];
                            $result['metadata']['stock_qty']    = $sd['stock_qty'];
                            $result['metadata']['manage_stock'] = $sd['manage_stock'];
                            if (!empty($sd['variants'])) {
                                $result['metadata']['variants'] = $sd['variants'];
                            }
                        }
                    }
                    unset($result);
                    $this->logger->info('[STEP 4b] Stock enrichment', [
                        'session'      => $sid,
                        'skus_checked' => count($skus),
                        'configurable' => array_values(array_filter(array_map(
                            function ($sku) use ($stockData) {
                                $sd = $stockData[$sku] ?? null;
                                if (!$sd || empty($sd['variants'])) {
                                    return null;
                                }
                                return [
                                    'sku'            => $sku,
                                    'in_stock'       => $sd['in_stock'],
                                    'variants_found' => count($sd['variants']),
                                ];
                            },
                            $skus
                        ))),
                    ]);

                    // Step 4c: Enrich RAG results with customer-group-specific prices and tier prices
                    $customerGroupId = (int)($customerData['group_id'] ?? 0);
                    $priceData = $this->productSearch->getPriceDataForSkus($skus, $customerGroupId);
                    foreach ($ragResults as &$result) {
                        $sku = $result['metadata']['sku'] ?? '';
                        if ($sku && isset($priceData[$sku])) {
                            $pd = $priceData[$sku];
                            $result['metadata']['list_price'] = $pd['list_price'];
                            $result['metadata']['price']      = $pd['group_price'];
                            if (!empty($pd['tier_prices'])) {
                                $result['metadata']['tier_prices'] = $pd['tier_prices'];
                            }
                        }
                    }
                    unset($result);
                    $this->logger->info('[STEP 4c] Price enrichment', [
                        'session'        => $sid,
                        'customer_group' => $customerGroupId,
                        'skus_enriched'  => count($priceData),
                    ]);
                }
            }

            // Step 5: Conversation history — load newest-first, then reverse to chronological
            // DESC+reverse ensures sessions longer than 20 turns still provide the most recent context.
            $history = array_reverse($this->messageResource->getMessagesByConversationId(
                (int)$conversation->getId(), 20, 'DESC'
            ));
            $this->pipelineLogger->section('CONVERSATION HISTORY (last 20 messages)');
            $this->pipelineLogger->data('Messages (' . count($history) . ')', $history);
            $this->logger->info('[STEP 5] Conversation history', [
                'session'      => $sid,
                'message_count'=> count($history),
            ]);

            // Step 5b: Load active cart contents — added to customerData so ContextBuilder
            // can include them in the LLM prompt under "=== AKTUELLER WARENKORB ==="
            try {
                $cartContents = $this->cartManager->getCartContents((int)$customerData['id'], $storeId);
                $customerData['cart_items'] = !empty($cartContents) ? $cartContents : null;
            } catch (\Throwable $e) {
                $customerData['cart_items'] = null;
                $this->logger->warning('[STEP 5b] Cart lookup failed – ' . $e->getMessage());
            }
            $this->logger->info('[STEP 5b] Cart contents', [
                'session'      => $sid,
                'items_count'  => $customerData['cart_items']['items_count'] ?? 0,
                'subtotal'     => $customerData['cart_items']['subtotal'] ?? 0,
            ]);

            // Step 5c: Load available payment methods + saved Vault tokens for LLM context
            try {
                $customerData['payment_methods'] = $this->paymentInfoProvider->getForCustomer(
                    (int)$customerData['id'],
                    $storeId
                );
            } catch (\Throwable $e) {
                $customerData['payment_methods'] = [];
                $this->logger->warning('[STEP 5c] Payment methods lookup failed – ' . $e->getMessage());
            }

            // Step 6: LLM intent detection — pass pre-extracted attachments to avoid double-processing
            $this->logger->info('[STEP 6] LLM intent (attachments: ' . count($attachments) . ')', [
                'session'          => $sid,
                'attachment_files' => array_column($attachments, 'filename'),
            ]);
            $degraded = $this->productIndexer->isSearchDegraded()
                     || $this->queryBuilder->isDegraded();
            $llmResult = $this->intentProcessor->process(
                $message, $customerData, $orders, $ragResults, $history, $attachments, $extractedAttachments, $resolvedQueryForLlm, $degraded, $queryType
            );
            $this->logger->info('[STEP 6] LLM result', [
                'session'          => $sid,
                'intent'           => $llmResult['intent'] ?? '?',
                'confidence'       => $llmResult['confidence'] ?? 0,
                'tool_calls'       => array_column($llmResult['tool_calls'] ?? [], 'name'),
                'products_to_show' => $llmResult['product_ids_to_show'] ?? [],
                'response_preview' => mb_substr($llmResult['response_text'] ?? '', 0, 300),
            ]);

            // Step 6b: LLM classified this as an auto-reply — persist inbound, send nothing
            if (($llmResult['intent'] ?? '') === 'auto_reply') {
                $this->logger->info('[STEP 6b] Auto-reply (LLM) detected — suppressing response to prevent loop', [
                    'session' => $sid,
                    'from'    => $message->getCustomerIdentifier(),
                ]);
                $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                    'intent' => 'auto_reply',
                ]);
                return 'auto_reply';
            }

            // Step 7: Persist inbound message
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                'intent' => $llmResult['intent'] ?? 'unknown',
            ]);

            // Step 7b: Escalation detection — count consecutive clarification turns in history
            $consecutiveClarifications = 0;
            foreach (array_reverse($history) as $h) {
                if (($h['direction'] ?? '') === ConversationMessage::DIRECTION_OUTBOUND
                    && ($h['intent'] ?? '') === 'ask_clarification'
                ) {
                    $consecutiveClarifications++;
                } else {
                    break;
                }
            }
            $escalationReason = $this->escalationDetector->detect(
                $message->getContentText(),
                $llmResult,
                $ragResults,
                $consecutiveClarifications,
                $storeId
            );
            if ($escalationReason !== null) {
                $this->logger->warning('[STEP 7b] Escalation triggered', [
                    'session'         => $sid,
                    'conversation_id' => $conversation->getId(),
                    'reason'          => $escalationReason,
                ]);
                $this->escalationService->escalate($conversation, $escalationReason, $storeId);

                $pauseText = "Vielen Dank für Ihre Anfrage.\n\n"
                    . "Ihre Nachricht wurde zur Überprüfung an unser Team weitergeleitet. "
                    . "Wir melden uns in Kürze bei Ihnen.";
                $pauseHtml = '<p>Vielen Dank für Ihre Anfrage.</p>'
                    . '<p>Ihre Nachricht wurde zur Überprüfung an unser Team weitergeleitet. '
                    . 'Wir melden uns in Kürze bei Ihnen.</p>';

                $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_OUTBOUND, [
                    'content_text' => $pauseText,
                    'content_html' => $pauseHtml,
                    'intent'       => 'escalated',
                ]);
                try {
                    $channel = $this->channels[$message->getChannelType()] ?? $this->emailChannel;
                    $channel->sendResponse($message, $pauseText, $pauseHtml);
                } catch (\Throwable $e) {
                    $this->logger->warning('[STEP 7b] Escalation pause reply failed – ' . $e->getMessage());
                }
                $this->pipelineLogger->section('ESCALATED');
                $this->pipelineLogger->data('Reason', $escalationReason);
                return ['text' => $pauseText, 'html' => $pauseHtml];
            }

            // Steps 8–10 wrapped so that any unhandled exception still writes an outbound
            // record to the DB, keeping conversation history alternating for the next LLM call.
            $responseText       = $llmResult['response_text'] ?? '';
            $responseHtml       = $llmResult['response_html'] ?? '<p>' . nl2br(htmlspecialchars($responseText)) . '</p>';
            // Strip the degraded marker from display strings (HTML comment is invisible but ugly in plain text).
            // Keep the note visible to the customer; strip marker+note only for DB history storage.
            $historyText        = $this->contextBuilder->stripDegradedNote($responseText);
            $responseText       = str_replace(ContextBuilder::DEGRADED_MARKER, '', $responseText);
            $responseHtml       = str_replace(ContextBuilder::DEGRADED_MARKER, '', $responseHtml);
            $toolCalls          = $llmResult['tool_calls'] ?? [];
            $toolResults        = [];
            $outboundPersisted  = false;
            try {
                // Step 8: Execute tool_calls via MagentoToolExecutor
                if (!empty($toolCalls)) {
                    $this->logger->info('[STEP 8] Executing tool_calls', [
                        'session'    => $sid,
                        'tool_names' => array_column($toolCalls, 'name'),
                    ]);
                    foreach ($toolCalls as $toolCall) {
                        $toolName   = $toolCall['name'] ?? '';
                        $toolParams = $toolCall['params'] ?? [];
                        $this->logger->info('[STEP 8] Tool: ' . $toolName, ['params' => $toolParams]);
                        $toolResult = $this->toolExecutor->execute(
                            $toolName,
                            $toolParams,
                            $customerData,
                            $storeId
                        );
                        $toolResults[] = ['tool' => $toolName, 'result' => $toolResult];
                        $this->logger->info('[STEP 8] Tool result: ' . $toolName, [
                            'success' => $toolResult['success'] ?? false,
                            'error'   => $toolResult['error'] ?? null,
                        ]);
                        // If the tool returned explicit text, override LLM pre-written response
                        if (!empty($toolResult['response_text'])) {
                            $responseText = $toolResult['response_text'];
                            $responseHtml = $toolResult['response_html'] ?? '<p>' . nl2br(htmlspecialchars($responseText)) . '</p>';
                            $historyText  = $responseText;
                        }
                    }
                }

                // Step 9: Persist outbound BEFORE delivering to the client so that the DB
                // record exists before the browser can trigger a follow-up request.
                $outboundPersisted = true;
                $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_OUTBOUND, [
                    'content_text'  => $historyText,
                    'content_html'  => $responseHtml,
                    'intent'        => $llmResult['intent'] ?? 'unknown',
                    'intent_data'   => json_encode(['tool_calls' => $toolCalls, 'tool_results' => $toolResults]),
                ]);

                // Step 10: Send response via the appropriate channel
                $channel = $this->channels[$message->getChannelType()] ?? $this->emailChannel;
                $channel->sendResponse(
                    $message,
                    $responseText,
                    $responseHtml,
                    ['inline_images' => $llmResult['inline_images'] ?? []]
                );
                $this->logger->info('[STEP 10] Response sent', [
                    'session'          => $sid,
                    'to'               => $message->getCustomerIdentifier(),
                    'inline_images'    => count($llmResult['inline_images'] ?? []),
                    'response_preview' => mb_substr($responseText, 0, 300),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[MessageProcessor] Processing failed after inbound save – ' . $e->getMessage(), [
                    'session' => $sid,
                ]);
                $this->errorNotifier->notify('Pipeline-Fehler', $e->getMessage(), $storeId);
                if (!$outboundPersisted) {
                    // Ensure the LLM sees an assistant turn in history on the next request
                    // so it understands this message was not processed successfully.
                    $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_OUTBOUND, [
                        'content_text' => 'Ihre Nachricht konnte leider nicht verarbeitet werden. Bitte versuchen Sie es erneut.',
                        'intent'       => 'error',
                    ]);
                }
                throw $e;
            }

            // Update conversation status and force updated_at refresh so the admin
            // grid (sorted by updated_at DESC) always shows the conversation at the top.
            // Without setDataChanges(true), Magento skips the UPDATE when status is
            // unchanged and MySQL's ON UPDATE CURRENT_TIMESTAMP never fires.
            // Never overwrite STATUS_ESCALATED here — EscalationService already saved it.
            if ($conversation->getStatus() !== ConversationInterface::STATUS_ESCALATED) {
                $conversation->setStatus(
                    ($llmResult['intent'] ?? '') === 'ask_clarification'
                        ? ConversationInterface::STATUS_PENDING
                        : ConversationInterface::STATUS_OPEN
                )->setDataChanges(true);
                $this->conversationResource->save($conversation);
            }

            // Build webchat HTML: replace email CID references with inline Base64 data URIs
            // so the browser can display product images without needing MIME attachments.
            $webchatHtml = $responseHtml;
            foreach ($llmResult['inline_images'] ?? [] as $cid => $imgData) {
                $dataUri     = 'data:' . ($imgData['mime'] ?? 'image/jpeg') . ';base64,' . ($imgData['data'] ?? '');
                $webchatHtml = str_replace('cid:' . $cid, $dataUri, $webchatHtml);
            }

            $durationMs = (int)round((microtime(true) - $start) * 1000);
            $this->pipelineLogger->section('FINAL RESPONSE');
            $this->pipelineLogger->raw('Response text (plain)', $responseText);
            $this->pipelineLogger->raw('Response HTML', $responseHtml);
            $this->pipelineLogger->data('Intent', $llmResult['intent'] ?? '?');
            $this->pipelineLogger->data('Tool calls', array_column($toolCalls, 'name'));
            $this->pipelineLogger->finishRequest($durationMs);

            $this->logger->info('=== PIPELINE END ===', [
                'session'      => $sid,
                'duration_ms'  => $durationMs,
                'intent'       => $llmResult['intent'] ?? '?',
                'tool_calls'   => array_column($toolCalls, 'name'),
            ]);

            return ['text' => $responseText, 'html' => $webchatHtml];
        } finally {
            if ($storeId > 0) {
                $this->storeManager->setCurrentStore($originalStoreId);
            }
        }
    }

    private function sendUnauthorizedReply(UnifiedMessageInterface $message): void
    {
        $text = "Guten Tag,\n\n"
            . "vielen Dank für Ihre Nachricht.\n\n"
            . "Leider ist Ihre E-Mail-Adresse (" . $message->getCustomerIdentifier() . ") "
            . "in unserem System nicht als Kundenkonto hinterlegt. "
            . "Unser KI-Bestellassistent steht ausschließlich registrierten Kunden zur Verfügung.\n\n"
            . "Falls Sie Kunde werden möchten oder Ihre Adresse geändert hat, "
            . "wenden Sie sich bitte direkt an uns.\n\n"
            . "Mit freundlichen Grüßen\nIhr Shop-Team";

        $html = '<p>Guten Tag,</p>'
            . '<p>vielen Dank für Ihre Nachricht.</p>'
            . '<p>Leider ist Ihre E-Mail-Adresse (<strong>'
            . htmlspecialchars($message->getCustomerIdentifier())
            . '</strong>) in unserem System nicht als Kundenkonto hinterlegt. '
            . 'Unser KI-Bestellassistent steht ausschließlich registrierten Kunden zur Verfügung.</p>'
            . '<p>Falls Sie Kunde werden möchten oder Ihre Adresse geändert hat, '
            . 'wenden Sie sich bitte direkt an uns.</p>'
            . '<p>Mit freundlichen Grüßen<br>Ihr Shop-Team</p>';

        try {
            $channel = $this->channels[$message->getChannelType()] ?? $this->emailChannel;
            $channel->sendResponse($message, $text, $html);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ConversationalCommerce: Failed to send unauthorized reply – ' . $e->getMessage()
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function resolveCustomer(UnifiedMessageInterface $message): ?array
    {
        try {
            $customer = $this->customerLookup->findByEmail($message->getCustomerIdentifier());
            if ($customer) {
                $message->setMagentoCustomerId((int)$customer['id']);
                $message->setCustomerVerified(true);
            }
            return $customer;
        } catch (\Throwable $e) {
            $this->logger->warning('ConversationalCommerce: Customer lookup failed – ' . $e->getMessage());
            return null;
        }
    }

    private function getOrCreateConversation(
        UnifiedMessageInterface $message,
        ?array $customerData
    ): Conversation {
        $conversation = $this->conversationFactory->create();
        $this->conversationResource->loadBySessionId($conversation, $message->getSessionId(), $message->getStoreId());

        if (!$conversation->getId()) {
            $conversation->setSessionId($message->getSessionId())
                ->setChannelType($message->getChannelType())
                ->setCustomerEmail($message->getCustomerIdentifier())
                ->setMagentoCustomerId($message->getMagentoCustomerId())
                ->setStoreId($message->getStoreId())
                ->setStatus(ConversationInterface::STATUS_OPEN);
            $this->conversationResource->save($conversation);
        }

        return $conversation;
    }

    private function persistMessage(
        Conversation $conversation,
        UnifiedMessageInterface $message,
        string $direction,
        array $extra = []
    ): void {
        try {
            $msg = $this->messageFactory->create();
            $msg->setData([
                'conversation_id' => $conversation->getId(),
                'direction'       => $direction,
                'channel_type'    => $message->getChannelType(),
                'message_id'      => $message->getMessageId(),
                'content_text'    => $extra['content_text'] ?? $message->getContentText(),
                'content_html'    => $extra['content_html'] ?? null,
                'intent'          => $extra['intent'] ?? null,
                'intent_data'     => $extra['intent_data'] ?? null,
            ]);
            $this->messageResource->save($msg);
        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: Failed to persist message – ' . $e->getMessage());
        }
    }
}
