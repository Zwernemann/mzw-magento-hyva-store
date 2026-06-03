<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;
use Zwernemann\ConversationalCommerce\Model\Attachment\ExtractedAttachment;
use Zwernemann\ConversationalCommerce\Model\Llm\MagentoToolRegistry;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;

/**
 * Assembles the full LLM context from all available data sources:
 * – customer info
 * – order history
 * – RAG product search results
 * – conversation history
 */
class ContextBuilder
{
    private const XML_PATH_INSTRUCTIONS     = 'conversional_commerce/llm/system_prompt';
    private const XML_PATH_MAX_CHARS        = 'conversional_commerce/llm/history_message_max_chars';
    private const XML_PATH_HISTORY_TURNS    = 'conversional_commerce/llm/history_turns_main';
    private const XML_PATH_HISTORY_TURNS_ACCOUNT = 'conversional_commerce/llm/history_turns_account';

    /**
     * Sentinel prepended by the LLM to its degraded notice.
     * Stripped from history turns so the note never pollutes future context.
     * Public so MessageProcessor can strip it from display strings.
     */
    public const DEGRADED_MARKER = '<!--cc-degraded-->';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly PipelineLogger       $pipelineLogger,
        private readonly MagentoToolRegistry  $toolRegistry
    ) {}

    /**
     * Build the full system prompt sent to Claude.
     * = configurable instructions (from admin or built-in default) + always-fixed JSON schema.
     * The instructions part is cached by Anthropic on repeated calls.
     */
    public function buildSystemPrompt(): string
    {
        $instructions = (string)$this->config->getValue(self::XML_PATH_INSTRUCTIONS);

        $instructions .= "\n\n"
            . 'Der Kunde kann Dateianhänge mitsenden (PDF, Excel, Word). Diese können strukturierte '
            . 'Bestelllisten (Spalten wie SKU/Artikelnummer, Menge) oder unstrukturierte Informationen '
            . 'enthalten. Extrahiere Bestellpositionen aus Anhängen und befülle die entsprechenden Tool-Parameter.';

        $instructions .= "\n\n"
            . 'TOOL-SYSTEM: Im Kontext steht ein Katalog verfügbarer Magento-Aktionen (=== VERFÜGBARE MAGENTO-AKTIONEN ===). '
            . 'Befülle tool_calls mit den gewünschten Aktionen. Für reine Informationsantworten und Rückfragen bleibt tool_calls leer. '
            . 'Angezeigte Preise sind Katalog- bzw. Gruppenpreise. Warenkorb-Aktionen und Promotionen werden von Magento beim Bestellabschluss automatisch verrechnet. '
            . 'Das tatsächliche Warenkorb-Gesamttotal nach Regelanwendung steht im Kontext unter AKTUELLER WARENKORB.';

        $instructions .= "\n\n"
            . 'BESTELLFLUSS: Wenn der Kunde explizit "bestelle", "bitte bestell", "ich möchte bestellen" o.ä. sagt, '
            . 'rufe cart_add_item UND cart_checkout in einem einzigen tool_calls-Array auf — ohne separate Bestätigungsfrage. '
            . 'PO-Nummern und Zahlarten aus derselben Nachricht direkt an cart_checkout übergeben. '
            . 'Ausnahme: War der Warenkorb vor dieser Anfrage bereits befüllt (AKTUELLER WARENKORB enthält Artikel die nicht Teil '
            . 'dieser Bestellung sind), dann alle Positionen auflisten und einmalig fragen: "Soll ich alle X Positionen bestellen?"';

        $instructions .= "\n\n"
            . 'PRIORITÄT DER AKTUELLEN ANFRAGE: Die neueste Kundennachricht (=== AKTUELLE ANFRAGE ===) hat '
            . 'IMMER höchste Priorität. Beantworte ausschließlich diese Anfrage — unabhängig davon, was in '
            . 'früheren Nachrichten des Gesprächsverlaufs besprochen wurde. Der Verlauf dient nur als Kontext, '
            . 'nicht als Aufgabe. Wenn die aktuelle Anfrage nach Produkten fragt, zeige Produkte. Wenn sie nach '
            . 'dem Warenkorb fragt, zeige den Warenkorb. Leite NIEMALS auf ein anderes Thema um, das nicht in '
            . 'der aktuellen Anfrage steht.';

        $instructions .= "\n\n"
            . 'ATTRIBUT-FILTER: Nutze search_products_by_filter NUR wenn der Kunde nach einem konkreten '
            . 'Attributwert filtert — also wenn er explizit einen Feldnamen und einen Wert nennt '
            . '(z.B. "alle Artikel wo Supplier Auto Order = Nein", "Artikel von Lieferant Zwernemann", '
            . '"Produkte mit Kategorie CP"). Für allgemeine Themensuchen wie "Broschüren zum Thema '
            . 'Temperatur" oder "Artikel über Flow" sind die RAG-Suchergebnisse im Kontext bereits '
            . 'das richtige Ergebnis — nutze diese direkt, KEIN search_products_by_filter. '
            . 'Attribute_code: Übernimm den Key exakt aus den Pinecone-Metadaten inkl. attr_-Präfix '
            . '(z.B. attr_bb_supplier_auto_order) — NUR wenn dieser Key in den RAG-Suchergebnissen '
            . 'sichtbar ist. Erfinde keine Attribut-Codes. '
            . 'Boolean-Attribute: Nein/no/false → "0", Ja/yes/true → "1". '
            . 'Textfelder als Teilstring übergeben — kein exakter Match nötig.';

        $instructions .= "\n\n"
            . 'Wenn die Nachricht eine automatische Abwesenheitsbenachrichtigung, ein Out-of-Office-Reply '
            . 'oder ein sonstiger maschinell generierter Auto-Responder ist (erkennbar an Formulierungen '
            . 'wie „Ich bin derzeit nicht erreichbar", „I am out of office", „Sono in ferie" o.ä.), '
            . 'setze intent = auto_reply und lasse response_text sowie response_html leer. '
            . 'Es wird dann KEINE Antwort gesendet.';

        return $instructions;
    }

    /**
     * Build the user-facing message with full context.
     *
     * Text-type attachments (DOCX/XLSX raw XML) are embedded inline before the
     * current request section. PDF attachments are sent as Anthropic document blocks
     * (via buildDocumentBlocks()) and therefore not repeated here.
     *
     * @param array<int, array<string, mixed>> $orderHistory
     * @param array<int, array<string, mixed>> $ragResults
     * @param array<int, array<string, mixed>> $conversationHistory
     * @param ExtractedAttachment[]            $extractedAttachments
     */
    public function buildUserMessage(
        UnifiedMessageInterface $message,
        array $customerData,
        array $orderHistory,
        array $ragResults,
        array $conversationHistory = [],
        array $extractedAttachments = [],
        string $resolvedQuery = '',
        string $queryType = 'product'
    ): string {
        $parts = [];

        // Customer context
        $parts[] = '=== KUNDENDATEN ===';
        $parts[] = 'E-Mail: ' . ($message->getResolvedEmail() ?: $message->getCustomerIdentifier());
        if (!empty($customerData)) {
            $groupName = $customerData['group_name'] ?? ($customerData['group_id'] !== null ? 'Gruppe ' . $customerData['group_id'] : '');
            $parts[] = 'Name: ' . ($customerData['firstname'] ?? '') . ' ' . ($customerData['lastname'] ?? '')
                . ($groupName !== '' ? ' | Kundengruppe: ' . $groupName : '');
            $parts[] = 'Firma: ' . ($customerData['company'] ?? 'unbekannt');
        }

        // Customer shipping addresses with IDs (for address selection in orders)
        if (!empty($customerData['addresses'])) {
            $parts[] = '';
            $parts[] = '=== LIEFERADRESSEN DES KUNDEN ===';
            foreach ($customerData['addresses'] as $addr) {
                $addrId   = $addr['id'] ?? '?';
                $street   = is_array($addr['street'] ?? '') ? implode(', ', $addr['street']) : ($addr['street'] ?? '');
                $flags    = [];
                if ($addr['default_shipping'] ?? false) {
                    $flags[] = 'Standard-Lieferadresse';
                }
                if ($addr['default_billing'] ?? false) {
                    $flags[] = 'Standard-Rechnungsadresse';
                }
                $flagStr = $flags ? ' (' . implode(', ', $flags) . ')' : '';
                $parts[] = sprintf(
                    'ID %s: %s %s, %s %s, %s%s',
                    $addrId,
                    $addr['firstname'] ?? '',
                    $addr['lastname']  ?? '',
                    $street,
                    $addr['postcode']  ?? '',
                    $addr['city']      ?? '',
                    $flagStr
                );
            }
        }

        // Available payment methods + saved Vault tokens
        $paymentMethods = $customerData['payment_methods'] ?? [];
        if (!empty($paymentMethods)) {
            $parts[] = '';
            $parts[] = '=== VERFÜGBARE ZAHLARTEN ===';
            foreach ($paymentMethods as $m) {
                if (($m['type'] ?? '') === 'vault') {
                    $expires = !empty($m['expires']) ? ', läuft ab ' . $m['expires'] : '';
                    $parts[] = '- ' . $m['code'] . ': ' . $m['label'] . ' (gespeichert' . $expires . ')';
                } else {
                    $parts[] = '- ' . $m['code'] . ': ' . $m['label'];
                }
            }
        }

        $needsRag   = ($queryType === 'product');
        $showOrders = in_array($queryType, ['product', 'reorder', 'account_order'], true);

        // Order history — only for product search and order-related queries
        if ($showOrders && !empty($orderHistory)) {
            $parts[] = '';
            $parts[] = '=== BESTELLVERLAUF (letzte 10 Bestellungen) ===';
            foreach (array_slice($orderHistory, 0, 10) as $order) {
                $date  = substr($order['created_at'] ?? '', 0, 10);
                $total = number_format((float)($order['grand_total'] ?? 0), 2, ',', '.');
                $parts[] = sprintf(
                    'Bestellung #%s vom %s – %.2f EUR – Status: %s',
                    $order['increment_id'] ?? '?',
                    $date,
                    (float)($order['grand_total'] ?? 0),
                    $order['status'] ?? '?'
                );

                // List items of this order
                foreach ($order['items'] ?? [] as $item) {
                    $parts[] = sprintf(
                        '  • %s (SKU: %s) – %d Stück à %.2f EUR',
                        $item['name'] ?? '?',
                        $item['sku'] ?? '?',
                        (int)($item['qty_ordered'] ?? 0),
                        (float)($item['price'] ?? 0)
                    );
                }
            }
        } else {
            $parts[] = '';
            $parts[] = '=== BESTELLVERLAUF ===';
            $parts[] = 'Keine Bestellungen gefunden.';
        }

        // Active cart contents
        $parts[] = '';
        if (!empty($customerData['cart_items']['items'])) {
            $parts[] = '=== AKTUELLER WARENKORB ===';
            foreach ($customerData['cart_items']['items'] as $item) {
                $parts[] = sprintf(
                    '• %s (SKU: %s) – %d Stück à %.2f EUR = %.2f EUR',
                    $item['name'], $item['sku'], $item['qty'],
                    (float)$item['price'], (float)$item['row_total']
                );
            }
            $parts[] = sprintf('Zwischensumme: %.2f EUR', (float)($customerData['cart_items']['subtotal'] ?? 0));
        } else {
            $parts[] = '=== AKTUELLER WARENKORB ===';
            $parts[] = 'Warenkorb ist leer.';
        }

        // Conversational context hint: resolved product from ConversationalQueryBuilder
        if ($resolvedQuery !== '') {
            $parts[] = '';
            $parts[] = '=== KONVERSATIONSKONTEXT ===';
            $parts[] = 'Aufgelöster Suchbegriff: "' . $resolvedQuery . '"';
        }

        // RAG results
        if (!empty($ragResults)) {
            $parts[] = '';
            $parts[] = '=== PASSENDE PRODUKTE AUS DEM KATALOG (RAG) ===';
            foreach ($ragResults as $result) {
                $m = $result['metadata'] ?? [];

                $effectivePrice = (float)($m['price'] ?? 0);
                $listPrice      = (float)($m['list_price'] ?? $effectivePrice);
                $priceLabel     = number_format($effectivePrice, 2, ',', '.');
                if ($listPrice > $effectivePrice + 0.005) {
                    $priceLabel .= ' (Ihr Preis; Listenpreis: ' . number_format($listPrice, 2, ',', '.') . ' EUR)';
                }
                $parts[] = sprintf(
                    '• %s (SKU: %s) – %s EUR | Score: %.3f',
                    $m['name'] ?? '?',
                    $m['sku']  ?? '?',
                    $priceLabel,
                    (float)($result['score'] ?? 0)
                );
                if (!empty($m['short_desc'])) {
                    $parts[] = '  ' . $m['short_desc'];
                }
                if (!empty($m['description'])) {
                    $parts[] = '  ' . mb_substr($m['description'], 0, 400);
                }
                if (!empty($m['options'])) {
                    $parts[] = '  Konfigurierbare Optionen: ' . $m['options'];
                }
                if (!empty($m['tier_prices'])) {
                    $tierLines = [];
                    foreach ($m['tier_prices'] as $tp) {
                        if ((float)$tp['qty'] > 1.0) {
                            $tierLines[] = 'ab ' . (int)$tp['qty'] . ' Stück → '
                                . number_format((float)$tp['price'], 2, ',', '.') . ' EUR';
                        }
                    }
                    if ($tierLines) {
                        $parts[] = '  Staffelpreise: ' . implode(' | ', $tierLines);
                    }
                }
                if (isset($m['in_stock'])) {
                    if (isset($m['manage_stock']) && !$m['manage_stock']) {
                        $parts[] = '  Lagerbestand: Dauerhaft bestellbar (kein Bestandsmanagement)';
                    } elseif (!empty($m['variants'])) {
                        // Configurable product — show per-variant stock breakdown
                        $inParts     = [];
                        $outParts    = [];
                        $anyUnmanaged = false;
                        foreach ($m['variants'] as $v) {
                            $varManage = $v['manage_stock'] ?? ($v['qty'] !== null);
                            if ($v['in_stock']) {
                                if (!$varManage) {
                                    $inParts[]    = $v['option'] . ': bestellbar (kein Bestandsmanagement)';
                                    $anyUnmanaged = true;
                                } else {
                                    $inParts[] = $v['option'] . ': ' . (int)$v['qty'];
                                }
                            } else {
                                $outParts[] = $v['option'] . ': nicht vorrätig';
                            }
                        }
                        if ($m['in_stock']) {
                            $detail = implode(', ', $inParts);
                            if (!empty($outParts)) {
                                $detail .= ' | ' . implode(', ', $outParts);
                            }
                            if ($anyUnmanaged && (int)$m['stock_qty'] === 0) {
                                $parts[] = '  Lagerbestand: Bestellbar – Varianten: ' . $detail;
                            } else {
                                $parts[] = sprintf(
                                    '  Lagerbestand: %d Stück gesamt – Varianten: %s',
                                    (int)$m['stock_qty'],
                                    $detail
                                );
                            }
                        } else {
                            $parts[] = '  Lagerbestand: Alle Varianten ausverkauft';
                        }
                    } elseif ($m['in_stock']) {
                        $parts[] = sprintf('  Lagerbestand: %d Stück verfügbar', (int)$m['stock_qty']);
                    } else {
                        $parts[] = '  Lagerbestand: Nicht vorrätig (ausverkauft)';
                    }
                }
                if (!empty($m['product_id'])) {
                    $parts[] = '  Produkt-ID: product_' . $m['product_id']
                        . ' — für product_ids_to_show und <img src="cid:product_' . $m['product_id'] . '"> im HTML verwenden';
                }
                if (!empty($m['image_url'])) {
                    $parts[] = '  Bild-URL: ' . $m['image_url'];
                }
                if (!empty($m['attr_labels'])) {
                    $parts[] = '  Attribute: ' . $m['attr_labels'];
                }
            }

            // Provide a consolidated list of all product IDs so the LLM can reference them
            // when filling product_ids_to_show and when listing products in the text response.
            $allPids = array_values(array_filter(array_map(
                fn($r) => isset($r['metadata']['product_id'])
                    ? 'product_' . $r['metadata']['product_id']
                    : null,
                $ragResults
            )));
            if ($allPids) {
                $parts[] = '';
                $parts[] = '=== PRODUKT-IDs FÜR product_ids_to_show ===';
                $parts[] = 'Alle ' . count($allPids) . ' IDs MÜSSEN in product_ids_to_show stehen: '
                    . implode(', ', $allPids);
            }
        }

        // Embed processed attachments in the prompt
        foreach ($extractedAttachments as $attachment) {
            if ($attachment->getBlockType() === 'text') {
                // DOCX/XLSX: raw XML — LLM interprets structure
                $parts[] = '';
                $parts[] = '=== Anhang: ' . $attachment->getFilename() . ' (OOXML) ===';
                $parts[] = $attachment->getContent();
                $parts[] = '===';
            } elseif ($attachment->getBlockType() === 'warning') {
                // Legacy format — tell the LLM so it can inform the customer
                $parts[] = '';
                $parts[] = '=== Hinweis zu Anhang: ' . $attachment->getFilename() . ' ===';
                $parts[] = $attachment->getContent();
                $parts[] = '===';
            }
            // blockType='document' (PDF) is sent as an Anthropic document block — not embedded here
        }

        // Tool catalog — restrict to relevant tools per query type to reduce token count
        $toolAllowList = match($queryType) {
            'reorder'         => ['reorder_from_history', 'cart_add_item', 'cart_update_item', 'cart_remove_item', 'cart_checkout', 'get_order_history', 'redirect_to_store'],
            'account_order'   => ['get_order_history', 'get_order_detail', 'get_shipment_tracking', 'get_invoice', 'redirect_to_store'],
            'account_address' => ['get_shipping_addresses', 'add_shipping_address', 'set_order_shipping_address', 'redirect_to_store'],
            'account_general' => ['get_account_info', 'update_account_info', 'toggle_newsletter', 'get_wishlist', 'wishlist_add_item', 'wishlist_remove_item', 'wishlist_move_to_cart', 'set_stock_notification', 'apply_coupon_code', 'remove_coupon_code', 'redirect_to_store'],
            'cart'            => ['cart_add_item', 'cart_update_item', 'cart_remove_item', 'cart_checkout', 'apply_coupon_code', 'remove_coupon_code', 'redirect_to_store'],
            default           => [], // product: all tools
        };
        $toolCatalog = $this->toolRegistry->buildToolCatalog(
            (int)($customerData['store_id'] ?? 0),
            $toolAllowList
        );
        if ($toolCatalog !== '') {
            $parts[] = '';
            $parts[] = $toolCatalog;
        }

        // The actual customer request
        $parts[] = '';
        $parts[] = '=== AKTUELLE ANFRAGE DES KUNDEN ===';
        $parts[] = $message->getContentText();

        return implode("\n", $parts);
    }

    /**
     * Extract document blocks (PDFs) from processed attachments for the Anthropic API.
     *
     * @param ExtractedAttachment[] $extractedAttachments
     * @return array<int, array{media_type: string, data: string}>
     */
    public function buildDocumentBlocks(array $extractedAttachments): array
    {
        $blocks = [];
        foreach ($extractedAttachments as $attachment) {
            if ($attachment->getBlockType() === 'document') {
                $blocks[] = [
                    'media_type' => $attachment->getMediaType(),
                    'data'       => $attachment->getContent(),
                ];
            }
        }
        return $blocks;
    }

    /**
     * Build a native multi-turn messages array for the Anthropic API.
     *
     * Historical exchanges become alternating user/assistant turns (up to 4 messages,
     * configurable chars each — first+last half kept when truncated). The current message
     * with full context (customer data, orders, RAG) is appended as the final user turn —
     * without the inline history section, since history is expressed via the messages array.
     *
     * @param array<int, array<string, mixed>> $orderHistory
     * @param array<int, array<string, mixed>> $ragResults
     * @param array<int, array<string, mixed>> $conversationHistory
     * @param ExtractedAttachment[]            $extractedAttachments
     * @return array<int, array{role: string, content: string}>
     */
    public function buildMessages(
        UnifiedMessageInterface $message,
        array $customerData,
        array $orderHistory,
        array $ragResults,
        array $conversationHistory = [],
        array $extractedAttachments = [],
        string $resolvedQuery = '',
        bool $degraded = false,
        string $queryType = 'product'
    ): array {
        $messages = [];
        $lastRole = null;

        $maxChars = $this->getHistoryMaxChars();
        $turns    = ($queryType === 'product')
            ? $this->getHistoryTurnsMain()
            : $this->getHistoryTurnsAccount();
        foreach (array_slice($conversationHistory, -$turns) as $msg) {
            $role = ($msg['direction'] ?? '') === 'inbound' ? 'user' : 'assistant';
            $raw  = $this->stripDegradedNote($msg['content_text'] ?? '');
            $text = $this->truncateHistoryMessage($raw, $maxChars);

            if ($role === $lastRole && !empty($messages)) {
                // Merge consecutive same-role messages (edge case: two inbound in a row)
                $last      = array_pop($messages);
                $messages[] = ['role' => $role, 'content' => $last['content'] . "\n\n" . $text];
            } else {
                $messages[] = ['role' => $role, 'content' => $text];
            }
            $lastRole = $role;
        }

        // Current message: full context but WITHOUT inline history section (pass [] for history)
        $currentContent = $this->buildUserMessage(
            $message, $customerData, $orderHistory, $ragResults, [], $extractedAttachments, $resolvedQuery, $queryType
        );

        if ($degraded) {
            $currentContent .= "\n\n[SYSTEM: The AI search subsystem ran at reduced capacity for this request. "
                . "Please append one brief, friendly sentence in the customer's language at the very end of your response, "
                . "informing them that the product search may be incomplete due to temporary system load. "
                . "Start that sentence with the exact string '" . self::DEGRADED_MARKER . "' (it will be hidden from the customer — do not translate or omit it). "
                . "Do not let it overshadow the main response.]";
        }

        if ($lastRole === 'user' && !empty($messages)) {
            $last       = array_pop($messages);
            $messages[] = ['role' => 'user', 'content' => $last['content'] . "\n\n" . $currentContent];
        } else {
            $messages[] = ['role' => 'user', 'content' => $currentContent];
        }

        $this->pipelineLogger->section('LLM CONTEXT BUILD');
        $this->pipelineLogger->raw('System prompt', $this->buildSystemPrompt());
        $this->pipelineLogger->data('Messages array (' . count($messages) . ' turns)', $messages);

        return $messages;
    }

    /**
     * Strip the degraded-notice marker and everything after it from a stored response.
     * Called when loading history turns and when preparing the outbound text for storage.
     */
    public function stripDegradedNote(string $text): string
    {
        $pos = strpos($text, self::DEGRADED_MARKER);
        if ($pos === false) {
            return $text;
        }
        return rtrim(substr($text, 0, $pos));
    }

    private function getHistoryMaxChars(): int
    {
        $v = (int)$this->config->getValue(
            self::XML_PATH_MAX_CHARS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 2000;
    }

    private function getHistoryTurnsMain(): int
    {
        $v = (int)$this->config->getValue(
            self::XML_PATH_HISTORY_TURNS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 4;
    }

    private function getHistoryTurnsAccount(): int
    {
        $v = (int)$this->config->getValue(
            self::XML_PATH_HISTORY_TURNS_ACCOUNT,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 2;
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
