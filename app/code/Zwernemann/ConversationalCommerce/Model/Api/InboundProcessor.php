<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\Data\ConversationInterface;
use Zwernemann\ConversationalCommerce\Api\Data\InboundResponseInterface;
use Zwernemann\ConversationalCommerce\Api\InboundApiInterface;
use Zwernemann\ConversationalCommerce\Model\Conversation;
use Zwernemann\ConversationalCommerce\Model\ConversationMessage;
use Zwernemann\ConversationalCommerce\Model\ConversationFactory;
use Zwernemann\ConversationalCommerce\Model\ConversationMessageFactory;
use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;
use Zwernemann\ConversationalCommerce\Model\Llm\ContextBuilder;
use Zwernemann\ConversationalCommerce\Model\Llm\IntentProcessor;
use Zwernemann\ConversationalCommerce\Model\Magento\CartManager;
use Zwernemann\ConversationalCommerce\Model\Magento\CustomerLookup;
use Zwernemann\ConversationalCommerce\Model\Magento\OrderHistory;
use Zwernemann\ConversationalCommerce\Model\Magento\ProductSearch;
use Zwernemann\ConversationalCommerce\Model\Message\UnifiedMessage;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;
use Zwernemann\ConversationalCommerce\Model\Pipeline\MagentoToolExecutor;
use Zwernemann\ConversationalCommerce\Model\Rag\ConversationalQueryBuilder;
use Zwernemann\ConversationalCommerce\Model\Rag\ProductIndexer;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\Conversation as ConversationResource;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\ConversationMessage as MessageResource;

/**
 * REST API implementation of InboundApiInterface.
 *
 * Runs the full processing pipeline (customer lookup, LLM, tool execution,
 * DB persistence) but skips sendResponse() — the caller (external connector)
 * is responsible for delivering the response text to the end user.
 */
class InboundProcessor implements InboundApiInterface
{
    public function __construct(
        private readonly CustomerLookup             $customerLookup,
        private readonly OrderHistory               $orderHistory,
        private readonly ProductIndexer             $productIndexer,
        private readonly ProductSearch              $productSearch,
        private readonly IntentProcessor            $intentProcessor,
        private readonly CartManager                $cartManager,
        private readonly ConversationFactory        $conversationFactory,
        private readonly ConversationMessageFactory $messageFactory,
        private readonly ConversationResource       $conversationResource,
        private readonly MessageResource            $messageResource,
        private readonly InboundResponseFactory     $responseFactory,
        private readonly ScopeConfigInterface       $scopeConfig,
        private readonly EncryptorInterface         $encryptor,
        private readonly StoreManagerInterface      $storeManager,
        private readonly MagentoToolExecutor        $toolExecutor,
        private readonly ConversationalQueryBuilder $queryBuilder,
        private readonly LoggerInterface            $logger,
        private readonly PipelineLogger             $pipelineLogger
    ) {}

    public function processInbound(
        string $channelType,
        string $messageId,
        string $customerIdentifier,
        string $sessionId,
        string $contentText,
        string $connectorSecret,
        string $timestamp = ''
    ): InboundResponseInterface {
        $this->validateSecret($connectorSecret);
        $storeId = $this->resolveStoreIdBySecret($connectorSecret);

        $message = $this->buildUnifiedMessage(
            $channelType, $messageId, $customerIdentifier,
            $sessionId, $contentText, $timestamp
        );
        $message->setStoreId($storeId);

        $sid   = $message->getSessionId();
        $start = microtime(true);

        $this->pipelineLogger->startRequest($sid, $messageId, $channelType);
        $this->pipelineLogger->section('INBOUND MESSAGE');
        $this->pipelineLogger->data('Metadata', [
            'channel'    => $channelType,
            'from'       => $customerIdentifier,
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'timestamp'  => $timestamp,
            'store_id'   => $storeId,
        ]);
        $this->pipelineLogger->raw('Message body (full)', $contentText);

        $this->logger->info('=== INBOUND API PIPELINE START ===', [
            'session'      => $sid,
            'channel'      => $message->getChannelType(),
            'from'         => $message->getCustomerIdentifier(),
            'store_id'     => $storeId,
            'body_chars'   => strlen($message->getContentText()),
            'body_preview' => mb_substr($message->getContentText(), 0, 300),
        ]);

        // Step 1: Customer lookup
        $customerData = $this->resolveCustomer($message);
        if ($customerData === null) {
            $this->logger->warning('[STEP 1] Auth rejected — sender not registered', [
                'session'    => $sid,
                'identifier' => $message->getCustomerIdentifier(),
            ]);
            return $this->unauthorizedResponse($message);
        }

        $this->logger->info('[STEP 1] Customer resolved', [
            'session'     => $sid,
            'customer_id' => $customerData['id'],
        ]);

        // Step 2: Conversation session
        $conversation = $this->getOrCreateConversation($message, $customerData);
        $this->logger->info('[STEP 2] Conversation', [
            'session'         => $sid,
            'conversation_id' => $conversation->getId(),
        ]);

        $originalStoreId = (int)$this->storeManager->getStore()->getId();
        if ($storeId > 0) {
            $this->storeManager->setCurrentStore($storeId);
        }

        try {
            // Step 3: Order history
            $orders = $this->orderHistory->getByCustomerEmail(
                $message->getResolvedEmail(), 20, $storeId
            );

            // Step 3.5: Classify query — skip RAG for non-product queries (account, address, etc.)
            $recentHistory      = $this->messageResource->getMessagesByConversationId(
                (int)$conversation->getId(), $this->queryBuilder->getHistoryLoadCount(), 'DESC'
            );
            $queryBuilderResult = $this->queryBuilder->build(trim($message->getContentText()), $recentHistory);
            $needsRag           = $queryBuilderResult['needs_rag'];
            $conversationalQuery = $queryBuilderResult['query'];
            $this->logger->info('[ConversationalQueryBuilder] built', [
                'session'    => $sid,
                'query_type' => $queryBuilderResult['query_type'],
                'needs_rag'  => $needsRag,
            ]);

            // Step 4: Semantic product search
            $topK       = max(1, (int)($this->scopeConfig->getValue('conversional_commerce/pinecone/top_k') ?: 10));
            $ragResults = [];
            if ($needsRag) {
                try {
                    $ragResults = $this->productIndexer->search($conversationalQuery, $topK, $storeId);
                } catch (\Throwable $e) {
                    $this->logger->warning('[STEP 4] RAG search failed', ['error' => $e->getMessage()]);
                }
            }
            $this->logger->info('[STEP 4] RAG search', [
                'session' => $sid,
                'query'   => $needsRag ? $conversationalQuery : '(skipped — non-product query)',
                'hits'    => count($ragResults),
            ]);

            // Step 5: Conversation history — load newest-first, then reverse to chronological
            $history = array_reverse($this->messageResource->getMessagesByConversationId(
                (int)$conversation->getId(), 20, 'DESC'
            ));
            $this->pipelineLogger->section('CONVERSATION HISTORY (last 20 messages)');
            $this->pipelineLogger->data('Messages (' . count($history) . ')', $history);

            // Step 6: LLM processing
            $llmResult = $this->intentProcessor->process(
                $message, $customerData, $orders, $ragResults, $history
            );

            $this->logger->info('[STEP 6] LLM intent', [
                'session'    => $sid,
                'intent'     => $llmResult['intent'] ?? '?',
                'tool_calls' => array_column($llmResult['tool_calls'] ?? [], 'name'),
            ]);

            // Step 7: Persist inbound message
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_INBOUND, [
                'intent' => $llmResult['intent'] ?? 'unknown',
            ]);

            $responseText = $llmResult['response_text'] ?? '';
            $responseHtml = $llmResult['response_html'] ?? '<p>' . nl2br(htmlspecialchars($responseText)) . '</p>';
            // Strip degraded marker from display strings; remove marker+note from DB history.
            $marker       = ContextBuilder::DEGRADED_MARKER;
            $historyText  = rtrim((string)preg_replace('/' . preg_quote($marker, '/') . '.*/s', '', $responseText));
            $responseText = str_replace($marker, '', $responseText);
            $responseHtml = str_replace($marker, '', $responseHtml);
            $toolCalls    = $llmResult['tool_calls'] ?? [];

            // Step 8: Execute tool_calls via MagentoToolExecutor
            $toolResults = [];
            if (!empty($toolCalls)) {
                $this->logger->info('[STEP 8] Executing tool_calls', [
                    'session'    => $sid,
                    'tool_names' => array_column($toolCalls, 'name'),
                ]);
                foreach ($toolCalls as $toolCall) {
                    $toolName   = $toolCall['name'] ?? '';
                    $toolParams = $toolCall['params'] ?? [];
                    $this->logger->info('[STEP 8] Tool: ' . $toolName, ['params' => $toolParams]);
                    $toolResult = $this->toolExecutor->execute($toolName, $toolParams, $customerData, $storeId);
                    $toolResults[] = ['tool' => $toolName, 'result' => $toolResult];
                    $this->logger->info('[STEP 8] Tool result: ' . $toolName, [
                        'success' => $toolResult['success'] ?? false,
                        'error'   => $toolResult['error'] ?? null,
                    ]);
                    if (!empty($toolResult['response_text'])) {
                        $responseText = $toolResult['response_text'];
                        $responseHtml = $toolResult['response_html'] ?? '<p>' . nl2br(htmlspecialchars($responseText)) . '</p>';
                        $historyText  = $responseText;
                    }
                }
            }

            // Step 9: Persist outbound message
            $this->persistMessage($conversation, $message, ConversationMessage::DIRECTION_OUTBOUND, [
                'content_text' => $historyText,
                'content_html' => $responseHtml,
                'intent'       => $llmResult['intent'] ?? 'unknown',
                'intent_data'  => json_encode(['tool_calls' => $toolCalls, 'tool_results' => $toolResults]),
            ]);

            // Update conversation status
            $conversation->setStatus(
                ($llmResult['intent'] ?? '') === 'ask_clarification'
                    ? ConversationInterface::STATUS_PENDING
                    : ConversationInterface::STATUS_OPEN
            )->setDataChanges(true);
            $this->conversationResource->save($conversation);

            $products    = $this->extractProducts($llmResult['product_ids_to_show'] ?? [], $ragResults, $toolCalls);
            $messageType = $this->determineMessageType($llmResult, $products);

            $this->pipelineLogger->section('FINAL RESPONSE');
            $this->pipelineLogger->raw('Response text (plain)', $responseText);
            $this->pipelineLogger->raw('Response HTML', $responseHtml);
            $this->pipelineLogger->data('Intent', $llmResult['intent'] ?? '?');
            $this->pipelineLogger->data('Tool calls', array_column($toolCalls, 'name'));
            $this->pipelineLogger->data('Message type (for connector)', $messageType);
            $this->pipelineLogger->data('Products JSON', $products);

            $durationMs = (int)round((microtime(true) - $start) * 1000);
            $this->pipelineLogger->finishRequest($durationMs);

            $this->logger->info('=== INBOUND API PIPELINE END ===', [
                'session'      => $sid,
                'duration_ms'  => $durationMs,
                'intent'       => $llmResult['intent'] ?? '?',
                'tool_calls'   => array_column($toolCalls, 'name'),
            ]);

            /** @var InboundResponse $response */
            $response = $this->responseFactory->create();
            return $response
                ->setSuccess(true)
                ->setResponseText($responseText)
                ->setResponseHtml($responseHtml)
                ->setIntent($llmResult['intent'] ?? '')
                ->setActionType(implode(',', array_column($toolCalls, 'name')))
                ->setMessageType($messageType)
                ->setProductsJson((string)json_encode($products));
        } finally {
            if ($storeId > 0) {
                $this->storeManager->setCurrentStore($originalStoreId);
            }
        }
    }

    /** @param array<string, mixed> $ragResults */
    private function extractProducts(array $productKeys, array $ragResults, array $toolCalls = []): array
    {
        // If the LLM called a tool, the tool's response_text is the answer — no products needed.
        // If there are no tool_calls and no explicit product_ids_to_show, fall back to RAG results
        // as a safety net in case the LLM forgot to fill product_ids_to_show for a product query.
        if (empty($productKeys)) {
            return empty($toolCalls)
                ? $this->productsFromRag($ragResults, count($ragResults))
                : [];
        }

        $products = [];
        foreach ($productKeys as $key) {
            foreach ($ragResults as $item) {
                $meta = $item['metadata'] ?? [];
                if ('product_' . ($meta['product_id'] ?? '') !== $key) {
                    continue;
                }
                $products[] = $this->ragItemToProduct($meta);
                break;
            }
        }
        return $products;
    }

    private function productsFromRag(array $ragResults, int $limit): array
    {
        $products = [];
        foreach (array_slice($ragResults, 0, $limit) as $item) {
            $meta = $item['metadata'] ?? [];
            if (empty($meta['sku'])) {
                continue;
            }
            $products[] = $this->ragItemToProduct($meta);
        }
        return $products;
    }

    /** @param array<string, mixed> $meta */
    private function ragItemToProduct(array $meta): array
    {
        return [
            'id'                => (string)($meta['product_id'] ?? ''),
            'sku'               => $meta['sku'] ?? '',
            'name'              => $meta['name'] ?? '',
            'price'             => (float)($meta['price'] ?? 0),
            'image_url'         => $meta['image_url'] ?? '',
            'short_description' => $meta['short_desc'] ?? '',
        ];
    }

    private function determineMessageType(array $llmResult, array $products): string
    {
        if (!empty($products)) {
            return 'product_list';
        }
        $toolNames = array_column($llmResult['tool_calls'] ?? [], 'name');
        if (in_array('cart_checkout', $toolNames, true)) {
            return 'order_confirmation';
        }
        return 'text';
    }

    /**
     * Validates the connector secret against all configured stores (and global scope).
     * Checks per-store secrets first, then falls back to global default scope.
     */
    private function validateSecret(string $connectorSecret): void
    {
        // Check per-store secrets (multi-store setup)
        foreach ($this->storeManager->getStores() as $store) {
            $encrypted = $this->scopeConfig->getValue(
                'conversional_commerce/whatsapp/connector_secret',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );
            $expected = $encrypted ? $this->encryptor->decrypt($encrypted) : '';
            if ($expected !== '' && hash_equals($expected, $connectorSecret)) {
                return;
            }
        }

        // Fall back to global default scope (single-store / legacy setup)
        $encrypted = (string)$this->scopeConfig->getValue('conversional_commerce/whatsapp/connector_secret');
        $expected  = $encrypted !== '' ? $this->encryptor->decrypt($encrypted) : '';
        if ($expected !== '' && hash_equals($expected, $connectorSecret)) {
            return;
        }

        throw new AuthorizationException(__('Invalid connector secret.'));
    }

    /**
     * Returns the store ID whose connector_secret matches the given secret.
     * Falls back to the default store view if no per-store secret matches.
     */
    private function resolveStoreIdBySecret(string $connectorSecret): int
    {
        foreach ($this->storeManager->getStores() as $store) {
            $encrypted = $this->scopeConfig->getValue(
                'conversional_commerce/whatsapp/connector_secret',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );
            $expected = $encrypted ? $this->encryptor->decrypt($encrypted) : '';
            if ($expected !== '' && hash_equals($expected, $connectorSecret)) {
                return (int)$store->getId();
            }
        }
        return (int)$this->storeManager->getDefaultStoreView()->getId();
    }

    private function buildUnifiedMessage(
        string $channelType,
        string $messageId,
        string $customerIdentifier,
        string $sessionId,
        string $contentText,
        string $timestamp
    ): UnifiedMessageInterface {
        $msg = new UnifiedMessage();
        $msg->setChannelType($channelType)
            ->setMessageId($messageId)
            ->setSessionId($sessionId ?: $customerIdentifier)
            ->setCustomerIdentifier($customerIdentifier)
            ->setContentText($contentText)
            ->setTimestamp($timestamp ?: date('c'));
        return $msg;
    }

    /** @return array<string, mixed>|null */
    private function resolveCustomer(UnifiedMessageInterface $message): ?array
    {
        try {
            $channel    = $message->getChannelType();
            $identifier = $message->getCustomerIdentifier();

            $customer = ($channel === 'whatsapp')
                ? $this->customerLookup->findByPhone($identifier)
                : $this->customerLookup->findByEmail($identifier);

            if ($customer) {
                $message->setMagentoCustomerId((int)$customer['id']);
                $message->setCustomerVerified(true);
                $message->setResolvedEmail($customer['email']);
            }
            return $customer;
        } catch (\Throwable $e) {
            $this->logger->warning('InboundProcessor: Customer lookup failed – ' . $e->getMessage());
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
                ->setCustomerEmail($message->getResolvedEmail())
                ->setMagentoCustomerId($message->getMagentoCustomerId())
                ->setStoreId($message->getStoreId())
                ->setStatus(ConversationInterface::STATUS_OPEN);
            $this->conversationResource->save($conversation);
        }

        return $conversation;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $customerData
     * @param array<string, mixed> $llmResult
     */
    private function handleOrderCreation(
        array $action, array $customerData, array &$llmResult,
        string $responseText, string $responseHtml, int $storeId = 0
    ): string {
        $items = $action['order_items'] ?? [];
        if (empty($items)) {
            return $responseText;
        }

        $skus          = array_column($items, 'sku');
        $validProducts = $this->productSearch->getMultipleBySkus($skus);
        $validItems    = [];
        $missingSkus   = [];

        foreach ($items as $item) {
            if (isset($validProducts[$item['sku']])) {
                $validItems[] = $item;
            } else {
                $missingSkus[] = $item['sku'];
            }
        }

        if (!empty($missingSkus)) {
            $this->logger->warning('InboundProcessor: Invalid SKUs: ' . implode(', ', $missingSkus));
        }

        if (empty($validItems)) {
            $llmResult['response_text'] = 'Die angegebenen Produkte konnten nicht im Katalog gefunden werden. '
                . 'Bitte prüfen Sie Ihre Anfrage.';
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
            return $llmResult['response_text'];
        }

        $poNumber    = (string)($action['po_number'] ?? '');
        $orderResult = $this->cartManager->createOrder(
            (int)$customerData['id'],
            $validItems,
            $customerData,
            $poNumber,
            $storeId
        );

        if (!$orderResult['success'] && ($orderResult['error'] ?? '') === 'needs_po_number') {
            $askText = 'Für Ihre Bestellung benötigen wir eine Bestellnummer (Purchase Order Number / PO-Nummer). '
                . 'Bitte antworten Sie mit Ihrer PO-Nummer.';
            $llmResult['response_text'] = $askText;
            $llmResult['response_html'] = '<p>' . nl2br(htmlspecialchars($askText)) . '</p>';
            $llmResult['intent'] = 'ask_clarification';
            return $askText;
        }

        if ($orderResult['success']) {
            $orderRef = $orderResult['increment_id'] ?? (string)$orderResult['order_id'];
            $itemList = implode(', ', array_map(
                fn($i) => ($i['qty'] ?? 1) . 'x ' . ($i['name'] ?? $i['sku']),
                $validItems
            ));
            $llmResult['response_text'] = sprintf(
                "Ihre Bestellung #%s wurde erfolgreich angelegt.\n\nBestellte Artikel:\n%s\n\n"
                . "Sie erhalten in Kürze eine Auftragsbestätigung.",
                $orderRef, $itemList
            );
            $llmResult['response_html'] = '<p>' . nl2br(htmlspecialchars($llmResult['response_text'])) . '</p>';
        } else {
            $llmResult['response_text'] = 'Die Bestellung konnte leider nicht automatisch angelegt werden. '
                . 'Bitte kontaktieren Sie uns direkt. Fehler: ' . ($orderResult['error'] ?? 'Unbekannt');
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
        }

        return $llmResult['response_text'];
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
            $this->logger->error('InboundProcessor: Failed to persist message – ' . $e->getMessage());
        }
    }

    private function unauthorizedResponse(UnifiedMessageInterface $message): InboundResponseInterface
    {
        $text = "Guten Tag,\n\n"
            . "vielen Dank für Ihre Nachricht.\n\n"
            . "Leider ist Ihre Nummer (" . $message->getCustomerIdentifier() . ") "
            . "in unserem System nicht als Kundenkonto hinterlegt. "
            . "Unser KI-Bestellassistent steht ausschließlich registrierten Kunden zur Verfügung.\n\n"
            . "Mit freundlichen Grüßen\nIhr Shop-Team";

        /** @var InboundResponse $response */
        $response = $this->responseFactory->create();
        return $response
            ->setSuccess(false)
            ->setErrorMessage('unauthorized')
            ->setResponseText($text)
            ->setResponseHtml('<p>' . nl2br(htmlspecialchars($text)) . '</p>')
            ->setIntent('unauthorized')
            ->setActionType('none');
    }
}
