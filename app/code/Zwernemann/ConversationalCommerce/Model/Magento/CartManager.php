<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Magento;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\Shop\CartServiceInterface;

/**
 * Creates Magento orders programmatically using native service contracts.
 *
 * Flow:
 * 1. Create customer cart (quote)
 * 2. Add products via Quote::addProduct() — handles configurable products via super_attribute
 * 3. Set shipping/billing address from customer data
 * 4. Collect shipping rates, pick configured or first available method
 * 5. Set payment method (from admin config)
 * 6. Place order via CartManagementInterface
 */
class CartManager implements CartServiceInterface
{
    private const XML_PATH_PAYMENT_METHOD = 'conversional_commerce/payment/method';
    private const XML_PATH_PO_MODE        = 'conversional_commerce/payment/po_number_mode';
    private const XML_PATH_SHIPPING       = 'conversional_commerce/payment/shipping_method';

    public function __construct(
        private readonly CartManagementInterface    $cartManagement,
        private readonly CartRepositoryInterface    $cartRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface   $orderRepository,
        private readonly ConfigurableType           $configurableType,
        private readonly ScopeConfigInterface       $scopeConfig,
        private readonly StoreManagerInterface      $storeManager,
        private readonly OrderSender                $orderSender,
        private readonly SearchCriteriaBuilder      $searchCriteriaBuilder,
        private readonly LoggerInterface            $logger,
        private readonly RegionFactory              $regionFactory
    ) {}

    /**
     * @param  int    $customerId
     * @param  array<int, array{sku: string, qty: int, options?: array<string,string>}> $items
     * @param  array<string, mixed> $customerData
     * @param  string $poNumber  Purchase Order number (empty = not yet provided)
     * @return array{success: bool, order_id: int|null, increment_id: string|null, error: string|null}
     */
    public function createOrder(
        int $customerId,
        array $items,
        array $customerData,
        string $poNumber = '',
        int $storeId = 0
    ): array {
        try {
            $paymentMethod   = (string)($this->scopeConfig->getValue(self::XML_PATH_PAYMENT_METHOD) ?? 'checkmo');
            $poMode          = (string)($this->scopeConfig->getValue(self::XML_PATH_PO_MODE) ?? 'none');
            $preferredShip   = (string)($this->scopeConfig->getValue(self::XML_PATH_SHIPPING) ?? '');

            if ($paymentMethod === '') {
                $paymentMethod = 'checkmo';
            }

            // Resolve PO number before touching the quote
            if ($poMode === 'ask_customer' && $poNumber === '') {
                $this->logger->info(
                    'ConversationalCommerce: Order creation paused — PO number required (ask_customer mode).'
                );
                return ['success' => false, 'order_id' => null, 'increment_id' => null, 'error' => 'needs_po_number'];
            }
            if ($poMode === 'auto_generate' && $poNumber === '') {
                $poNumber = 'CC-' . date('YmdHis') . '-' . $customerId;
                $this->logger->info('ConversationalCommerce: Auto-generated PO number: ' . $poNumber);
            }
            // purchaseorder always requires a po_number — auto-generate a UID if none was provided
            if ($paymentMethod === 'purchaseorder' && $poNumber === '') {
                $poNumber = 'CC-' . date('YmdHis') . '-' . $customerId;
                $this->logger->info('ConversationalCommerce: Auto-generated PO reference for purchaseorder: ' . $poNumber);
            }

            $cartId = $this->cartManagement->createEmptyCartForCustomer($customerId);
            $quote  = $this->cartRepository->get($cartId);

            // Remove any items left from previous failed attempts (createEmptyCartForCustomer
            // returns the existing active cart rather than a truly fresh one)
            if ($quote->getItemsCount() > 0) {
                $this->logger->info(sprintf(
                    'ConversationalCommerce: Cart %d already has %d item(s) — clearing before adding new items.',
                    $cartId, $quote->getItemsCount()
                ));
                $quote->removeAllItems();
            }

            // Add products — collect all per-item errors before aborting
            $stockErrors = [];
            foreach ($items as $item) {
                $product = $this->productRepository->get($item['sku']);
                $this->logger->info('ConversationalCommerce: Adding product to cart', [
                    'sku'     => $item['sku'],
                    'qty'     => $item['qty'] ?? 1,
                    'type'    => $product->getTypeId(),
                    'weight'  => $product->getWeight(),
                    'status'  => $product->getStatus(),
                    'in_stock'=> $product->isInStock(),
                ]);

                $request = ($product->getTypeId() === 'configurable' && !empty($item['options']))
                    ? $this->resolveConfigurableRequest($product, $item)
                    : new DataObject(['qty' => $item['qty'] ?? 1]);

                try {
                    $result = $quote->addProduct($product, $request);
                    if (is_string($result)) {
                        $stockErrors[] = '"' . $product->getName() . '" (SKU: ' . $item['sku'] . '): ' . $result;
                    }
                } catch (\Magento\Framework\Exception\LocalizedException $e) {
                    $stockErrors[] = '"' . $product->getName() . '" (SKU: ' . $item['sku'] . '): ' . $e->getMessage();
                }
            }

            if (!empty($stockErrors)) {
                return $this->err('Nicht genug Bestand für: ' . implode('; ', $stockErrors));
            }

            // Set addresses on the quote's own address objects (not via AddressFactory)
            // so the address is properly linked to the quote for shipping rate collection.
            $addressData = $this->buildAddressData($customerData);
            $this->logger->info('ConversationalCommerce: Address data for quote', [
                'firstname'  => $addressData['firstname']  ?? '(empty)',
                'lastname'   => $addressData['lastname']   ?? '(empty)',
                'street'     => $addressData['street']     ?? '(empty)',
                'city'       => $addressData['city']       ?? '(empty)',
                'postcode'   => $addressData['postcode']   ?? '(empty)',
                'country_id' => $addressData['country_id'] ?? '(empty)',
                'telephone'  => $addressData['telephone']  ?? '(empty)',
                'email'      => $addressData['email']      ?? '(empty)',
                'source'     => count($customerData['addresses'] ?? []) . ' address(es) in customer data',
            ]);

            $quote->getShippingAddress()->addData($addressData);
            $quote->getBillingAddress()->addData($addressData);

            // Persist items + address to DB FIRST — shipping carriers (e.g. matrixrate)
            // query the DB for quote items when calculating rates.
            $this->cartRepository->save($quote);
            $quote = $this->cartRepository->get($cartId);

            // Force the quote onto the correct frontend store so collectShippingRates()
            // uses the right website/store as a regular checkout would.
            // When running from cron/email (storeId=0) fall back to the default store view.
            $store = $storeId > 0
                ? $this->storeManager->getStore($storeId)
                : $this->storeManager->getDefaultStoreView();
            $quote->setStore($store)->setStoreId($store->getId());

            // Log what the quote's shipping address looks like after reload
            $shippingAddress = $quote->getShippingAddress();
            $this->logger->info('ConversationalCommerce: Shipping address after reload', [
                'firstname'  => $shippingAddress->getFirstname(),
                'lastname'   => $shippingAddress->getLastname(),
                'street'     => implode(', ', (array)$shippingAddress->getStreet()),
                'city'       => $shippingAddress->getCity(),
                'postcode'   => $shippingAddress->getPostcode(),
                'country_id' => $shippingAddress->getCountryId(),
                'quote_id'   => $shippingAddress->getQuoteId(),
                'item_count' => $quote->getItemsCount(),
                'item_qty'   => $quote->getItemsQty(),
                'store_id'   => $quote->getStoreId(),
                'website_id' => $store->getWebsiteId(),
            ]);

            // Products like brochures often have weight=0 which causes weight-based carriers
            // (e.g. matrix rate) to find no matching rule. Set a minimum so rate tables hit.
            if ((float)$shippingAddress->getWeight() < 0.001) {
                $shippingAddress->setWeight(0.001);
            }

            $shippingAddress->setCollectShippingRates(true)->collectShippingRates();

            $this->logger->info('ConversationalCommerce: Quote weight after collect', [
                'quote_weight'   => $shippingAddress->getWeight(),
                'subtotal'       => $shippingAddress->getSubtotal(),
                'country_id'     => $shippingAddress->getCountryId(),
                'postcode'       => $shippingAddress->getPostcode(),
                'item_count'     => $quote->getItemsCount(),
                'item_qty'       => $quote->getItemsQty(),
            ]);

            $allRates = $shippingAddress->getAllShippingRates();
            $this->logger->info('ConversationalCommerce: Available shipping rates', [
                'rates' => array_map(fn($r) => [
                    'code'  => $r->getCode(),
                    'title' => $r->getCarrierTitle() . ' – ' . $r->getMethodTitle(),
                    'price' => $r->getPrice(),
                ], $allRates),
            ]);

            $shippingMethod = $this->pickShippingMethod($allRates, $preferredShip);
            $this->logger->info('ConversationalCommerce: Using shipping method: ' . $shippingMethod);

            // Set shipping method, collect totals, then re-apply the method.
            // collectTotals() can reset the shipping method on the address when the
            // Shipping total collector re-evaluates rates. Re-setting after collectTotals
            // ensures the method survives to the final save and placeOrder() validation.
            $shippingAddress->setShippingMethod($shippingMethod);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $quote->getShippingAddress()->setShippingMethod($shippingMethod);

            $this->logger->info('ConversationalCommerce: Shipping method after collectTotals', [
                'method'            => $quote->getShippingAddress()->getShippingMethod(),
                'shipping_amount'   => $quote->getShippingAddress()->getShippingAmount(),
            ]);

            $this->cartRepository->save($quote);

            // Payment — importData() is sufficient, no need for setPaymentMethod()
            $paymentData = ['method' => $paymentMethod];
            if ($paymentMethod === 'purchaseorder') {
                $paymentData['po_number'] = $poNumber;
            }
            $this->logger->info('ConversationalCommerce: Payment data', [
                'method'    => $paymentMethod,
                'po_mode'   => $poMode,
                'po_number' => $poNumber ?: '—',
                'cart_id'   => $cartId,
                'customer'  => $customerId,
            ]);

            $quote->getPayment()->importData($paymentData);
            $this->cartRepository->save($quote);

            $this->logger->info('ConversationalCommerce: Pre-placeOrder state', [
                'shipping_method' => $quote->getShippingAddress()->getShippingMethod(),
                'payment_method'  => $quote->getPayment()->getMethod(),
                'item_count'      => $quote->getItemsCount(),
                'grand_total'     => $quote->getGrandTotal(),
            ]);

            // Place order
            $orderId     = (int)$this->cartManagement->placeOrder($cartId);
            $order       = $this->orderRepository->get($orderId);
            $incrementId = $order->getIncrementId();

            $this->logger->info(sprintf(
                'ConversationalCommerce: Order %s (id=%d) created for customer %d',
                $incrementId, $orderId, $customerId
            ));

            // Explicitly send Magento's order confirmation email.
            // In Magento 2.4+ emails are queued asynchronously by default and may
            // never be dispatched from within a cron job. Calling send() here forces
            // immediate delivery regardless of async-email settings.
            // Guard: placeOrder() may already have sent it (synchronous webchat context) —
            // skip to avoid duplicate emails.
            try {
                if (!$order->getEmailSent()) {
                    $this->orderSender->send($order, true);
                    $this->logger->info('ConversationalCommerce: Order confirmation email sent for ' . $incrementId);
                } else {
                    $this->logger->info('ConversationalCommerce: Order confirmation email already sent by placeOrder() for ' . $incrementId);
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ConversationalCommerce: Order confirmation email failed for ' . $incrementId
                    . ' – ' . $e->getMessage()
                );
            }

            return ['success' => true, 'order_id' => $orderId, 'increment_id' => $incrementId, 'error' => null];

        } catch (\Throwable $e) {
            $this->logger->error(
                'ConversationalCommerce: Order creation failed – ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );
            return $this->err($e->getMessage());
        }
    }

    /**
     * @return array{items: array, subtotal: float, items_count: int}|array{}
     */
    public function getCartContents(int $customerId, int $storeId = 0): array
    {
        try {
            $quote = $this->getActiveCart($customerId, $storeId);
            if ($quote === null) {
                return [];
            }
            $items = [];
            foreach ($quote->getItemsCollection() as $item) {
                if ($item->getParentItemId()) {
                    continue; // skip child items of configurables
                }
                $items[] = [
                    'sku'       => (string)$item->getSku(),
                    'name'      => (string)$item->getName(),
                    'qty'       => (int)$item->getQty(),
                    'price'     => (float)$item->getPrice(),
                    'row_total' => (float)$item->getRowTotal(),
                ];
            }
            return [
                'items'       => $items,
                'subtotal'    => (float)$quote->getSubtotal(),
                'items_count' => count($items),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('ConversationalCommerce: getCartContents failed – ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array<int, array{sku: string, qty: int, name?: string, options?: array<string,string>}> $items
     * @return array{success: bool, cart: array, error: string|null}
     */
    public function addItemsToCart(int $customerId, array $items, array $customerData, int $storeId = 0): array
    {
        try {
            $quote = $this->getActiveCart($customerId, $storeId);
            if ($quote === null) {
                $cartId = $this->cartManagement->createEmptyCartForCustomer($customerId);
                $quote  = $this->cartRepository->get($cartId);
            }
            $cartId = (int)$quote->getId();

            $itemErrors = [];
            foreach ($items as $item) {
                try {
                    $product = $this->productRepository->get($item['sku']);
                    $request = ($product->getTypeId() === 'configurable' && !empty($item['options']))
                        ? $this->resolveConfigurableRequest($product, $item)
                        : new DataObject(['qty' => $item['qty'] ?? 1]);
                    $result = $quote->addProduct($product, $request);
                    if (is_string($result)) {
                        $this->logger->warning('ConversationalCommerce: cart_add – cannot add ' . $item['sku'] . ': ' . $result);
                        $itemErrors[$item['sku']] = $result;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('ConversationalCommerce: cart_add – SKU ' . $item['sku'] . ' failed: ' . $e->getMessage());
                    $itemErrors[$item['sku']] = $e->getMessage();
                }
            }

            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->cartRepository->save($quote);

            $allFailed = !empty($itemErrors) && count($itemErrors) === count($items);
            return [
                'success'     => !$allFailed,
                'cart'        => $this->getCartContents($customerId, $storeId),
                'error'       => $allFailed ? reset($itemErrors) : null,
                'item_errors' => $itemErrors,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: addItemsToCart failed – ' . $e->getMessage());
            return $this->cartErr($e->getMessage());
        }
    }

    /**
     * @return array{success: bool, cart: array, error: string|null}
     */
    public function updateCartItem(int $customerId, string $sku, int $qty, int $storeId = 0): array
    {
        if ($qty <= 0) {
            return $this->removeCartItem($customerId, $sku, $storeId);
        }
        try {
            $quote = $this->getActiveCart($customerId, $storeId);
            if ($quote === null) {
                return $this->cartErr('Kein aktiver Warenkorb vorhanden.');
            }
            $found = false;
            foreach ($quote->getItemsCollection() as $item) {
                if ($item->getParentItemId()) {
                    continue;
                }
                if ($item->getSku() === $sku) {
                    $item->setQty($qty);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return $this->cartErr('Artikel ' . $sku . ' nicht im Warenkorb gefunden.');
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->cartRepository->save($quote);
            return ['success' => true, 'cart' => $this->getCartContents($customerId, $storeId), 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: updateCartItem failed – ' . $e->getMessage());
            return $this->cartErr($e->getMessage());
        }
    }

    /**
     * @return array{success: bool, cart: array, error: string|null}
     */
    public function removeCartItem(int $customerId, string $sku, int $storeId = 0): array
    {
        try {
            $quote = $this->getActiveCart($customerId, $storeId);
            if ($quote === null) {
                return $this->cartErr('Kein aktiver Warenkorb vorhanden.');
            }
            $found = false;
            foreach ($quote->getItemsCollection() as $item) {
                if ($item->getParentItemId()) {
                    continue;
                }
                if ($item->getSku() === $sku) {
                    $quote->removeItem($item->getId());
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return $this->cartErr('Artikel ' . $sku . ' nicht im Warenkorb gefunden.');
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->cartRepository->save($quote);
            return ['success' => true, 'cart' => $this->getCartContents($customerId, $storeId), 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: removeCartItem failed – ' . $e->getMessage());
            return $this->cartErr($e->getMessage());
        }
    }

    /**
     * Check out the customer's existing active cart as a Magento order.
     *
     * @param array<string, mixed> $customerData
     * @return array{success: bool, order_id: int|null, increment_id: string|null, error: string|null}
     */
    public function checkoutCart(int $customerId, array $customerData, string $poNumber = '', int $storeId = 0): array
    {
        try {
            // Use createEmptyCartForCustomer() to obtain the cart ID — same as createOrder().
            // CartManagement::placeOrder() expects a cart ID registered via this API.
            // Using getList() + getId() gives a quote ID that placeOrder() cannot resolve.
            $cartId = $this->cartManagement->createEmptyCartForCustomer($customerId);
            $quote  = $this->cartRepository->get($cartId);
            if ($quote->getItemsCount() === 0) {
                return $this->err('Der Warenkorb ist leer. Bitte füge zuerst Produkte hinzu.');
            }

            $configPayment = (string)($this->scopeConfig->getValue(self::XML_PATH_PAYMENT_METHOD) ?? 'checkmo') ?: 'checkmo';
            $paymentMethod = !empty($customerData['_payment_method']) ? (string)$customerData['_payment_method'] : $configPayment;
            $poMode        = (string)($this->scopeConfig->getValue(self::XML_PATH_PO_MODE) ?? 'none');
            $preferredShip = (string)($this->scopeConfig->getValue(self::XML_PATH_SHIPPING) ?? '');

            // LLM-provided PO number overrides passed argument
            if (!empty($customerData['_po_number'])) {
                $poNumber = (string)$customerData['_po_number'];
            }

            if ($poMode === 'ask_customer' && $poNumber === '') {
                return $this->err('needs_po_number');
            }
            if (($poMode === 'auto_generate' || $paymentMethod === 'purchaseorder') && $poNumber === '') {
                $poNumber = 'CC-' . date('YmdHis') . '-' . $customerId;
            }

            $addressData = $this->buildAddressData($customerData);
            $quote->getShippingAddress()->addData($addressData);
            $quote->getBillingAddress()->addData($addressData);

            $this->cartRepository->save($quote);
            $quote = $this->cartRepository->get($cartId);

            // Set store AFTER reload — same pattern as createOrder() so matrixrate
            // sees the correct website/store context when collecting shipping rates.
            $store = $storeId > 0
                ? $this->storeManager->getStore($storeId)
                : $this->storeManager->getDefaultStoreView();
            $quote->setStore($store)->setStoreId($store->getId());

            $shippingAddress = $quote->getShippingAddress();
            if ((float)$shippingAddress->getWeight() < 0.001) {
                $shippingAddress->setWeight(0.001);
            }
            $shippingAddress->setCollectShippingRates(true)->collectShippingRates();

            $allRates = $shippingAddress->getAllShippingRates();
            $this->logger->info('ConversationalCommerce: checkoutCart available shipping rates', [
                'rates' => array_map(fn($r) => [
                    'code'    => $r->getCode(),
                    'title'   => $r->getCarrierTitle() . ' – ' . $r->getMethodTitle(),
                    'price'   => $r->getPrice(),
                    'error'   => $r->getErrorMessage(),
                ], $allRates),
                'preferred' => $preferredShip,
            ]);

            $shippingMethod = $this->pickShippingMethod($allRates, $preferredShip);
            if ($shippingMethod === '') {
                return $this->err(
                    'Für Ihre Lieferadresse ist keine Versandmethode verfügbar. '
                    . 'Bitte überprüfen Sie die Adresse oder wenden Sie sich an den Support.'
                );
            }
            // Mirror createOrder(): set → collectTotals → re-set → save, THEN set payment → save.
            // The intermediate save ensures the shipping method is persisted to quote_address
            // before placeOrder() reloads the quote from DB for validation.
            $shippingAddress->setShippingMethod($shippingMethod);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $quote->getShippingAddress()->setShippingMethod($shippingMethod);

            $this->logger->info('ConversationalCommerce: checkoutCart shipping after collectTotals', [
                'method'          => $quote->getShippingAddress()->getShippingMethod(),
                'shipping_amount' => $quote->getShippingAddress()->getShippingAmount(),
            ]);

            $this->cartRepository->save($quote); // persist shipping method before adding payment

            $paymentData = ['method' => $paymentMethod];
            if ($paymentMethod === 'purchaseorder') {
                $paymentData['po_number'] = $poNumber;
            }
            $quote->getPayment()->importData($paymentData);
            $this->cartRepository->save($quote); // persist payment

            $this->logger->info('ConversationalCommerce: checkoutCart pre-placeOrder', [
                'shipping_method' => $quote->getShippingAddress()->getShippingMethod(),
                'payment_method'  => $quote->getPayment()->getMethod(),
                'grand_total'     => $quote->getGrandTotal(),
            ]);

            $orderId     = (int)$this->cartManagement->placeOrder($cartId);
            $order       = $this->orderRepository->get($orderId);
            $incrementId = $order->getIncrementId();

            try {
                if (!$order->getEmailSent()) {
                    $this->orderSender->send($order, true);
                    $this->logger->info('ConversationalCommerce: checkoutCart email sent for ' . $incrementId);
                } else {
                    $this->logger->info('ConversationalCommerce: checkoutCart email already sent by placeOrder() for ' . $incrementId);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ConversationalCommerce: checkoutCart email failed – ' . $e->getMessage());
            }

            $this->logger->info(sprintf(
                'ConversationalCommerce: Cart checked out as order %s (id=%d) for customer %d',
                $incrementId, $orderId, $customerId
            ));

            return ['success' => true, 'order_id' => $orderId, 'increment_id' => $incrementId, 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: checkoutCart failed – ' . $e->getMessage());
            return $this->err($e->getMessage());
        }
    }

    private function getActiveCart(int $customerId, int $storeId = 0): ?\Magento\Quote\Api\Data\CartInterface
    {
        $builder = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->addFilter('is_active', 1);
        if ($storeId > 0) {
            $builder->addFilter('store_id', $storeId);
        }
        $criteria = $builder->setPageSize(1)->create();
        $list     = $this->cartRepository->getList($criteria);
        $items    = $list->getItems();
        return !empty($items) ? reset($items) : null;
    }

    /** @return array{success: false, cart: array, error: string} */
    private function cartErr(string $msg): array
    {
        return ['success' => false, 'cart' => [], 'error' => $msg];
    }

    /**
     * Build a buy request for a configurable product by mapping human-readable option
     * labels from the LLM response (e.g. {"color": "Rot"}) to Magento super_attribute IDs.
     *
     * @param array{sku: string, qty: int, options: array<string,string>} $item
     */
    private function resolveConfigurableRequest(
        \Magento\Catalog\Model\Product $product,
        array $item
    ): DataObject {
        $superAttribute = [];
        try {
            $configAttributes = $this->configurableType->getConfigurableAttributes($product);

            foreach ($configAttributes as $configAttr) {
                $attr = $configAttr->getProductAttribute();
                if (!$attr) {
                    continue;
                }
                $attrId    = (int)$configAttr->getAttributeId();
                $attrCode  = strtolower($attr->getAttributeCode());
                $attrLabel = strtolower((string)($attr->getDefaultFrontendLabel() ?? ''));
                $allOpts   = $attr->getSource()->getAllOptions(false);
                $optLabels = array_map(fn($o) => (string)($o['label'] ?? ''), $allOpts);

                // Pass 1: key-based match — LLM key equals attribute code or frontend label
                $requestedValue = null;
                $matchedBy      = null;
                foreach ($item['options'] as $k => $v) {
                    if (strtolower($k) === $attrCode || strtolower($k) === $attrLabel) {
                        $requestedValue = $v;
                        $matchedBy      = 'key:' . $k;
                        break;
                    }
                }

                // Pass 2: value-based match — scan this attribute's option labels for any LLM value.
                // Handles shops with custom attribute codes (e.g. bb_size, clothing_size)
                // where the LLM sends a generic key ("size") that doesn't match the code.
                if ($requestedValue === null) {
                    foreach ($allOpts as $opt) {
                        $optLabel = strtolower((string)($opt['label'] ?? ''));
                        foreach ($item['options'] as $k => $v) {
                            if ($optLabel === strtolower((string)$v)) {
                                $requestedValue = $v;
                                $matchedBy      = 'value:' . $k . '=' . $v . ' in ' . $attrCode;
                                break 2;
                            }
                        }
                    }
                }

                $this->logger->info('ConversationalCommerce: Configurable attribute lookup', [
                    'sku'            => $item['sku'],
                    'attr_id'        => $attrId,
                    'attr_code'      => $attrCode,
                    'attr_label'     => $attrLabel,
                    'available_vals' => $optLabels,
                    'requested_opts' => $item['options'],
                    'matched_by'     => $matchedBy ?? 'none',
                    'requested_val'  => $requestedValue,
                ]);

                if ($requestedValue !== null) {
                    foreach ($allOpts as $opt) {
                        if (strtolower((string)($opt['label'] ?? '')) === strtolower((string)$requestedValue)) {
                            $superAttribute[$attrId] = $opt['value'];
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ConversationalCommerce: resolveConfigurableRequest failed for ' . $item['sku']
                . ' – ' . $e->getMessage()
            );
        }

        if (empty($superAttribute)) {
            $this->logger->warning(
                'ConversationalCommerce: No super_attribute resolved for configurable product '
                . $item['sku'] . '. Options: ' . json_encode($item['options'])
            );
        }

        return new DataObject(['qty' => $item['qty'] ?? 1, 'super_attribute' => $superAttribute]);
    }

    /**
     * @param \Magento\Quote\Model\Quote\Address\Rate[] $rates
     * @return string Empty string when no valid rate is found
     */
    private function pickShippingMethod(array $rates, string $preferred = ''): string
    {
        // Filter out error/unavailable rates — only keep usable ones.
        // A valid rate always has a non-empty code, a numeric price, and no error message.
        // (Rate::isError() does not exist in Magento OS — use getErrorMessage() instead.)
        $valid = array_filter(
            $rates,
            static fn($r) => $r->getCode() !== '' && $r->getPrice() !== null && !$r->getErrorMessage()
        );

        // Try configured preferred method first
        if ($preferred !== '') {
            foreach ($valid as $rate) {
                if ($rate->getCode() === $preferred) {
                    return $preferred;
                }
            }
            if (!empty($valid)) {
                $this->logger->warning(
                    'ConversationalCommerce: Preferred shipping method "' . $preferred
                    . '" not available — falling back to first available rate.'
                );
            }
        }

        // Take the first valid rate
        foreach ($valid as $rate) {
            $this->logger->info('ConversationalCommerce: Using shipping method "' . $rate->getCode() . '".');
            return $rate->getCode();
        }

        // No valid rates — caller must handle this
        $this->logger->error('ConversationalCommerce: No valid shipping rates available for this quote.');
        return '';
    }

    /** @return array<string, mixed> */
    private function buildAddressData(array $customerData): array
    {
        // 1. Inline address supplied by tool executor (new, not yet saved to customer account)
        if (!empty($customerData['_inline_shipping_address'])) {
            return $this->normaliseInlineAddress($customerData['_inline_shipping_address'], $customerData);
        }

        // 2. Specific address ID selected by LLM from the addresses context block
        if (!empty($customerData['_shipping_address_id'])) {
            $id = (int)$customerData['_shipping_address_id'];
            foreach ($customerData['addresses'] ?? [] as $addr) {
                if ((int)($addr['id'] ?? 0) === $id) {
                    return $this->normaliseAddress($addr, $customerData);
                }
            }
            $this->logger->warning(
                'ConversationalCommerce: shipping_address_id ' . $id . ' not found in customer addresses — falling back to default.'
            );
        }

        // 3. Default billing address
        foreach ($customerData['addresses'] ?? [] as $addr) {
            if ($addr['default_billing'] ?? false) {
                return $this->normaliseAddress($addr, $customerData);
            }
        }
        // 4. Default shipping address
        foreach ($customerData['addresses'] ?? [] as $addr) {
            if ($addr['default_shipping'] ?? false) {
                return $this->normaliseAddress($addr, $customerData);
            }
        }
        return [
            'firstname'  => $customerData['firstname'] ?? 'Unknown',
            'lastname'   => $customerData['lastname']  ?? 'Customer',
            'street'     => ['Unbekannt 1'],
            'city'       => 'Unbekannt',
            'postcode'   => '00000',
            'country_id' => 'DE',
            'telephone'  => '0000',
            'email'      => $customerData['email'] ?? '',
            'region_id'  => 0,
            'region'     => '',
        ];
    }

    /** @return array<string, mixed> */
    private function normaliseInlineAddress(array $addr, array $customerData): array
    {
        $street    = $addr['street'] ?? '';
        $countryId = strtoupper($addr['country_id'] ?? 'DE');
        $postcode  = $addr['postcode'] ?? '';
        $regionId  = 0;

        // Auto-resolve German Bundesland from PLZ prefix when region not specified
        if ($countryId === 'DE' && $postcode !== '' && empty($addr['region_id'])) {
            $regionId = $this->resolveRegionIdByPostcode($postcode);
        } elseif (!empty($addr['region_id'])) {
            $regionId = $this->resolveRegionId((int)$addr['region_id'], $countryId);
        }

        return [
            'firstname'  => $addr['firstname']  ?? $customerData['firstname'] ?? '',
            'lastname'   => $addr['lastname']   ?? $customerData['lastname']  ?? '',
            'street'     => is_array($street) ? $street : [$street],
            'city'       => $addr['city']       ?? '',
            'postcode'   => $postcode,
            'country_id' => $countryId,
            'telephone'  => $addr['telephone']  ?? $customerData['telephone'] ?? '0000',
            'email'      => $customerData['email'] ?? '',
            'region_id'  => $regionId,
            'region'     => '',
        ];
    }

    /** Resolves a German Bundesland region_id from a PLZ prefix via the Magento directory table. */
    private function resolveRegionIdByPostcode(string $postcode): int
    {
        $prefix = (int)substr($postcode, 0, 2);
        // PLZ prefix → ISO 3166-2:DE code mapping
        $map = [
            1 => 'SN', 2 => 'SH', 3 => 'NI', 4 => 'NW', 5 => 'NW',
            6 => 'HE', 7 => 'BW', 8 => 'BY', 9 => 'BY', 10 => 'BE',
            12 => 'BE', 13 => 'BE', 14 => 'BB', 15 => 'BB', 16 => 'BB',
            17 => 'MV', 18 => 'MV', 19 => 'MV', 20 => 'HH', 21 => 'HH',
            22 => 'HH', 23 => 'SH', 24 => 'SH', 25 => 'SH', 26 => 'NI',
            27 => 'NI', 28 => 'HB', 29 => 'NI', 30 => 'NI', 31 => 'NI',
            32 => 'NW', 33 => 'NW', 34 => 'HE', 35 => 'HE', 36 => 'HE',
            37 => 'NI', 38 => 'NI', 39 => 'ST', 40 => 'NW', 41 => 'NW',
            42 => 'NW', 44 => 'NW', 45 => 'NW', 46 => 'NW', 47 => 'NW',
            48 => 'NW', 49 => 'NI', 50 => 'NW', 51 => 'NW', 52 => 'NW',
            53 => 'NW', 54 => 'RP', 55 => 'RP', 56 => 'RP', 57 => 'NW',
            58 => 'NW', 59 => 'NW', 60 => 'HE', 61 => 'HE', 63 => 'HE',
            64 => 'HE', 65 => 'HE', 66 => 'SL', 67 => 'RP', 68 => 'BW',
            69 => 'BW', 70 => 'BW', 71 => 'BW', 72 => 'BW', 73 => 'BW',
            74 => 'BW', 75 => 'BW', 76 => 'BW', 77 => 'BW', 78 => 'BW',
            79 => 'BW', 80 => 'BY', 81 => 'BY', 82 => 'BY', 83 => 'BY',
            84 => 'BY', 85 => 'BY', 86 => 'BY', 87 => 'BY', 88 => 'BW',
            89 => 'BW', 90 => 'BY', 91 => 'BY', 92 => 'BY', 93 => 'BY',
            94 => 'BY', 95 => 'BY', 96 => 'BY', 97 => 'BY', 98 => 'TH',
            99 => 'TH',
        ];

        $code = $map[$prefix] ?? null;
        if ($code === null) {
            return 0;
        }
        try {
            $region = $this->regionFactory->create()->loadByCode($code, 'DE');
            return (int)($region->getId() ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return array<string, mixed> */
    private function normaliseAddress(array $addr, array $customerData): array
    {
        $countryId = $addr['country_id'] ?? 'DE';
        // Validate the stored region_id against the country — addData() only overrides keys
        // that are explicitly passed, so we must always include region_id to prevent stale
        // data on a recycled quote address from surviving. resolveRegionId() returns 0 for
        // region_ids that exist in the DB but belong to a different country (e.g. region_id
        // 116 on a DE address), while preserving valid US/CH region codes.
        $regionId = $this->resolveRegionId((int)($addr['region_id'] ?? 0), $countryId);
        return [
            'firstname'  => $addr['firstname']  ?? $customerData['firstname'] ?? '',
            'lastname'   => $addr['lastname']   ?? $customerData['lastname']  ?? '',
            'street'     => $addr['street']     ?? [''],
            'city'       => $addr['city']       ?? '',
            'postcode'   => $addr['postcode']   ?? '',
            'country_id' => $countryId,
            'telephone'  => $addr['telephone']  ?? '',
            'email'      => $customerData['email'] ?? '',
            'region_id'  => $regionId,
            'region'     => $regionId > 0 ? (string)($addr['region'] ?? '') : '',
        ];
    }

    private function resolveRegionId(int $regionId, string $countryId): int
    {
        if ($regionId <= 0) {
            return 0;
        }
        try {
            $region = $this->regionFactory->create()->load($regionId);
            if ($region->getId() && $region->getCountryId() === $countryId) {
                return $regionId;
            }
            $this->logger->info(sprintf(
                'ConversationalCommerce: region_id %d is not valid for country %s (belongs to %s) — clearing.',
                $regionId, $countryId, (string)$region->getCountryId()
            ));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ConversationalCommerce: region_id validation failed – ' . $e->getMessage()
            );
        }
        return 0;
    }

    /** @return array{success: false, order_id: null, increment_id: null, error: string} */
    private function err(string $msg): array
    {
        return ['success' => false, 'order_id' => null, 'increment_id' => null, 'error' => $msg];
    }
}
