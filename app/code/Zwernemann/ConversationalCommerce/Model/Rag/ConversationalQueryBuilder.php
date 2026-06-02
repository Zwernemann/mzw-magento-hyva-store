<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Rag;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\LlmClientInterface;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;

/**
 * Reformulates the current customer message into a product search query
 * by taking recent conversation history into account.
 *
 * A short continuation like "ja bitte 10 mal in den warenkorb" is meaningless
 * for a vector search. This class asks Haiku: "what product is the customer
 * actually referring to?" — and returns e.g. "Bobby Anti-Theft Backpack 08-0279".
 *
 * Replaces the heuristic chain-traversal + outbound-snippet approach.
 */
class ConversationalQueryBuilder
{
    private const SYSTEM = 'Webshop query classifier for an LLM-based B2B shop assistant. '
        . 'Classify the latest user message into exactly one query_type: '
        . '"product" = product search, product info, product comparison, "show me X", "what is Y". '
        . '"reorder" = re-ordering something from past orders ("nochmal", "wie letzte Mal", "from my last order"). '
        . '"account_order" = order status, tracking, invoice, shipment ("wo ist", "Bestellstatus", "Rechnung zu", "Sendung"). '
        . '"account_address" = viewing or changing addresses ("Lieferadresse", "Rechnungsadresse", "welche Adressen"). '
        . '"account_general" = account info, newsletter, wishlist, coupon, name change, stock alert. '
        . '"cart" = cart operations: adding, removing, updating items, checkout, or confirming/continuing a previous product selection. '
        . 'SEARCH QUERY RULES: '
        . 'For product: return the product name/SKU/category as a clean search query. '
        . 'For cart/reorder: return the product name or SKU being added/reordered so the catalog can confirm the SKU. For short confirmations ("ja", "bitte", "10 stück") use the most recently discussed product name from history. '
        . 'For account_order: return empty string. '
        . 'For account_address, account_general: return empty string (no product search needed).';

    private const TOOL_NAME   = 'return_search_query';
    private const TOOL_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'query_type'   => [
                'type'        => 'string',
                'enum'        => ['product', 'reorder', 'account_order', 'account_address', 'account_general', 'cart'],
                'description' => 'product: product search/info. reorder: re-order from history. account_order: order status/tracking/invoice. account_address: view/change addresses. account_general: account info, newsletter, wishlist, coupon. cart: cart operations without new product search.',
            ],
            'search_query' => [
                'type'        => 'string',
                'description' => 'Product name/SKU to search. Required for product and cart/reorder (use most recently discussed product for short confirmations like "ja"). Empty string for account_order, account_address, account_general.',
            ],
        ],
        'required' => ['query_type', 'search_query'],
    ];

    private const XML_PATH_HISTORY_MAX_CHARS = 'conversional_commerce/llm/history_message_max_chars';
    private const XML_PATH_HISTORY_TURNS     = 'conversional_commerce/llm/history_turns_query';

    private bool $degraded = false;

    public function __construct(
        private readonly LlmClientInterface   $llm,
        private readonly LoggerInterface      $logger,
        private readonly PipelineLogger       $pipelineLogger,
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * @param array<int, array<string, mixed>> $recentHistory  Last N messages from DB (direction + content_text)
     * @return array{query: string, query_type: string, needs_rag: bool}
     */
    public function build(string $currentMessage, array $recentHistory): array
    {
        $this->degraded = false;

        // Build a proper multi-turn messages array from DB history.
        // $recentHistory arrives in DESC order (newest first); take the 8 most recent,
        // reverse to chronological so Claude sees oldest→newest as native conversation turns.
        $maxChars = $this->getHistoryMaxChars();
        $turns    = $this->getHistoryTurnsQuery();
        $messages = [];
        $prevRole = null;
        foreach (array_reverse(array_slice($recentHistory, 0, $turns)) as $msg) {
            $role = ($msg['direction'] ?? '') === 'inbound' ? 'user' : 'assistant';
            if ($role === $prevRole) {
                continue; // Anthropic API requires alternating roles
            }
            $text = $this->truncateHistoryMessage(trim($msg['content_text'] ?? ''), $maxChars);
            if ($text !== '') {
                $messages[] = ['role' => $role, 'content' => $text];
                $prevRole = $role;
            }
        }
        // If history ends with a user message (no assistant reply in DB yet, e.g. due to a
        // processing error or a race between two rapid messages), drop it so the current
        // message is the sole final user turn — the Anthropic API requires alternating roles.
        if (!empty($messages) && ($messages[count($messages) - 1]['role'] ?? '') === 'user') {
            array_pop($messages);
        }
        // Current message is always the final user turn
        $messages[] = ['role' => 'user', 'content' => $currentMessage];

        $this->pipelineLogger->section('CONVERSATIONAL QUERY BUILDER (Haiku)');
        $this->pipelineLogger->data('Messages', count($messages));

        try {
            $result   = $this->llm->chatWithTool(
                $messages,
                self::SYSTEM,
                self::TOOL_NAME,
                self::TOOL_SCHEMA,
                $this->llm->getFastModelOptions()
            );
            $queryType = (string)($result['query_type'] ?? 'product');
            if (!in_array($queryType, ['product', 'reorder', 'account_order', 'account_address', 'account_general', 'cart'], true)) {
                $queryType = 'product';
            }
            $query = trim((string)($result['search_query'] ?? ''));
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'verload')) {
                $this->degraded = true;
            }
            $this->logger->warning('[ConversationalQueryBuilder] failed – ' . $e->getMessage());
            $queryType = 'product';
            $query     = '';
        }

        // Skip RAG only for pure account queries where product catalog is irrelevant
        $needsRag = !in_array($queryType, ['account_address', 'account_general'], true);

        // Fallback: if RAG needed but search_query is empty, use raw message
        if ($needsRag && $query === '') {
            $query = $currentMessage;
        }

        $this->pipelineLogger->data('Query type', $queryType);
        $this->pipelineLogger->data('Search query', $needsRag ? $query : '(skipped)');
        $this->logger->info('[ConversationalQueryBuilder] built', [
            'input_message' => mb_substr($currentMessage, 0, 100),
            'query_type'    => $queryType,
            'result_query'  => $needsRag ? $query : '(skipped)',
        ]);

        return ['query' => $query, 'query_type' => $queryType, 'needs_rag' => $needsRag];
    }

    /**
     * Returns the number of recent messages MessageProcessor should load from DB
     * for query building — slightly more than the configured turn count to ensure
     * the slice always has enough messages to work with.
     */
    public function getHistoryLoadCount(): int
    {
        return $this->getHistoryTurnsQuery() + 2;
    }

    private function getHistoryTurnsQuery(): int
    {
        $v = (int)$this->scopeConfig->getValue(
            self::XML_PATH_HISTORY_TURNS,
            ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 6;
    }

    private function getHistoryMaxChars(): int
    {
        $v = (int)$this->scopeConfig->getValue(
            self::XML_PATH_HISTORY_MAX_CHARS,
            ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 2000;
    }

    private function truncateHistoryMessage(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $half = (int)($maxChars / 2);
        return mb_substr($text, 0, $half) . '…' . mb_substr($text, -$half);
    }
}
