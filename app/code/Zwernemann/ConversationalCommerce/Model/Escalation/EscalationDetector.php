<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Escalation;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Evaluates whether an LLM response should trigger human escalation.
 *
 * Four independent triggers (all configurable):
 *  1. Confidence below threshold
 *  2. Second consecutive clarification question
 *  3. Keyword match in inbound message text
 *  4. Calculated order total exceeds the configured limit
 */
class EscalationDetector
{
    private const XML_ENABLED    = 'conversional_commerce/escalation/enabled';
    private const XML_CONFIDENCE = 'conversional_commerce/escalation/confidence_threshold';
    private const XML_KEYWORDS   = 'conversional_commerce/escalation/keywords';
    private const XML_ORDER_LIMIT = 'conversional_commerce/escalation/order_value_limit';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    /**
     * Returns a human-readable escalation reason or null if no trigger fires.
     *
     * @param string  $inboundText             Raw text of the customer's message
     * @param array   $llmResult               Decoded LLM response
     * @param array   $ragResults              Enriched RAG results (for price lookup)
     * @param int     $consecutiveClarifications Number of consecutive outbound ask_clarification turns BEFORE the current one
     * @param int     $storeId
     */
    public function detect(
        string $inboundText,
        array  $llmResult,
        array  $ragResults,
        int    $consecutiveClarifications,
        int    $storeId = 0
    ): ?string {
        [$scope, $code] = $storeId > 0
            ? [ScopeInterface::SCOPE_STORE, $storeId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];

        if (!$this->scopeConfig->isSetFlag(self::XML_ENABLED, $scope, $code)) {
            return null;
        }

        $isNowClarification = ($llmResult['intent'] ?? '') === 'ask_clarification';

        // Trigger 1: confidence below threshold — not fired for clarification intents;
        // Trigger 2 already handles the case where clarifications pile up.
        $threshold  = (float)($this->scopeConfig->getValue(self::XML_CONFIDENCE, $scope, $code) ?? 0.75);
        $confidence = (float)($llmResult['confidence'] ?? 1.0);
        if ($threshold > 0 && $confidence < $threshold && !$isNowClarification) {
            return sprintf(
                'KI-Konfidenz zu niedrig (%.0f%% < Schwellwert %.0f%%)',
                $confidence * 100,
                $threshold * 100
            );
        }

        // Trigger 2: second (or more) consecutive clarification question
        if ($isNowClarification && $consecutiveClarifications >= 1) {
            return sprintf(
                'Zweite Klärungsfrage in Folge (insgesamt %d)',
                $consecutiveClarifications + 1
            );
        }

        // Trigger 3: keyword match (case-insensitive)
        $kwConfig = (string)($this->scopeConfig->getValue(self::XML_KEYWORDS, $scope, $code) ?? '');
        $keywords = array_values(array_filter(array_map('trim', explode(',', $kwConfig))));
        $textLower = mb_strtolower($inboundText);
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_strpos($textLower, mb_strtolower($kw)) !== false) {
                return 'Schlüsselwort erkannt: ' . $kw;
            }
        }

        // Trigger 4: order value above limit — scan tool_calls for cart/order actions
        $limit = (float)($this->scopeConfig->getValue(self::XML_ORDER_LIMIT, $scope, $code) ?? 0);
        if ($limit > 0) {
            $orderToolNames = ['cart_add_item', 'cart_checkout'];
            $orderItems = [];
            foreach ($llmResult['tool_calls'] ?? [] as $tc) {
                if (in_array($tc['name'] ?? '', $orderToolNames, true)) {
                    foreach ($tc['params']['items'] ?? [] as $item) {
                        $orderItems[] = $item;
                    }
                }
            }
            if (!empty($orderItems)) {
                $total = $this->calculateOrderTotal($orderItems, $ragResults);
                if ($total > $limit) {
                    return sprintf(
                        'Bestellwert %.2f EUR überschreitet Freigabelimit %.2f EUR',
                        $total,
                        $limit
                    );
                }
            }
        }

        return null;
    }

    /** Sums qty × price for each order item using enriched RAG results for pricing. */
    private function calculateOrderTotal(array $orderItems, array $ragResults): float
    {
        $prices = [];
        foreach ($ragResults as $r) {
            $sku = $r['metadata']['sku'] ?? '';
            if ($sku !== '') {
                $prices[$sku] = (float)($r['metadata']['price'] ?? $r['metadata']['list_price'] ?? 0);
            }
        }
        $total = 0.0;
        foreach ($orderItems as $item) {
            $total += ($prices[$item['sku'] ?? ''] ?? 0.0) * (float)($item['qty'] ?? 1);
        }
        return $total;
    }
}
