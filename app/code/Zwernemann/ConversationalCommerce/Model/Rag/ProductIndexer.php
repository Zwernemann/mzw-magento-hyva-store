<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Rag;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\Magento\CatalogSearch;

/**
 * Indexes Magento products into Pinecone via Voyage AI embeddings.
 * Uses per-store Pinecone namespaces (store_{storeCode}) to isolate results.
 * Uses a checksum to skip products that haven't changed since last index run.
 *
 * search() performs hybrid retrieval:
 *   1. Keyword search via Magento full-text (Elasticsearch/MySQL) — finds exact name/SKU matches
 *   2. Semantic search via Voyage + Pinecone — finds thematically similar products
 *   Results are merged and deduplicated by SKU; hybrid matches are ranked highest.
 */
class ProductIndexer
{
    private const BATCH_SIZE = 48;   // Voyage max 128, Pinecone max 100 – stay conservative

    /** Attributes that are never included regardless of blacklist config */
    private const SYSTEM_ATTRIBUTE_BLACKLIST = [
        'status', 'visibility', 'tax_class_id', 'quantity_and_stock_status',
        'url_key', 'url_path', 'image', 'small_image', 'thumbnail', 'media_gallery',
        'swatch_image', 'price_view', 'shipment_type', 'custom_design',
        'custom_design_from', 'custom_design_to', 'custom_layout_update',
        'page_layout', 'gift_message_available', 'options_container',
        'required_options', 'has_options', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly CollectionFactory          $collectionFactory,
        private readonly ProductRepositoryInterface  $productRepository,
        private readonly VoyageClient               $voyage,
        private readonly PineconeClient             $pinecone,
        private readonly CatalogSearch              $catalogSearch,
        private readonly SearchTermExtractor        $termExtractor,
        private readonly StoreManagerInterface      $storeManager,
        private readonly ScopeConfigInterface       $config,
        private readonly LoggerInterface            $logger,
        private readonly \Magento\Framework\App\ResourceConnection $resource,
        private readonly ConfigurableType           $configurableType,
        private readonly EavConfig                  $eavConfig
    ) {}

    /**
     * Index all products (or only changed ones if $force = false).
     *
     * @param int $storeId  0 = iterate all active stores; >0 = index this store only
     */
    public function indexAll(bool $force = false, ?callable $progress = null, int $storeId = 0): void
    {
        if ($storeId > 0) {
            $store = $this->storeManager->getStore($storeId);
            $this->indexForStore($store, $force, $progress);
            return;
        }

        foreach ($this->storeManager->getStores(true) as $store) {
            $this->indexForStore($store, $force, $progress);
        }
    }

    private function indexForStore(StoreInterface $store, bool $force, ?callable $progress): void
    {
        $storeId   = (int)$store->getId();
        $namespace = 'store_' . $store->getCode();
        $baseUrl   = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');

        if ($force) {
            $this->pinecone->deleteNamespace($namespace);
        }

        $collection = $this->collectionFactory->create();
        $collection->setStore($storeId);
        $collection->addWebsiteFilter($store->getWebsiteId());
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', 1);
        $collection->addAttributeToFilter('visibility', ['neq' => 1]);
        $collection->setPageSize(200);

        $total     = $collection->getSize();
        $processed = 0;

        $categoryNames = $this->loadCategoryNames($storeId);

        $this->logger->info(sprintf(
            'ConversationalCommerce: Indexing %d products for store "%s" (namespace: %s)...',
            $total, $store->getCode(), $namespace
        ));

        $page = 1;
        do {
            $collection->setCurPage($page)->load();

            $textsToEmbed = [];
            $productData  = [];

            foreach ($collection as $product) {
                $checksum = $this->checksum($product);

                if (!$force && $this->isAlreadyIndexed((int)$product->getId(), $checksum, $storeId)) {
                    $processed++;
                    continue;
                }

                $catNames   = $this->resolveCategories($product->getCategoryIds(), $categoryNames);
                $customAttrs = $this->collectCustomAttributes($product, $storeId);
                $text = $this->buildEmbedText($product, $catNames, $customAttrs);
                $textsToEmbed[] = $text;
                $productData[]  = [
                    'product'     => $product,
                    'checksum'    => $checksum,
                    'baseUrl'     => $baseUrl,
                    'catNames'    => $catNames,
                    'customAttrs' => $customAttrs,
                ];
            }

            // Batch embedding
            for ($i = 0; $i < count($textsToEmbed); $i += self::BATCH_SIZE) {
                $batchTexts = array_slice($textsToEmbed, $i, self::BATCH_SIZE);
                $batchData  = array_slice($productData, $i, self::BATCH_SIZE);

                try {
                    $embeddings = $this->voyage->embedBatch($batchTexts);
                } catch (\Throwable $e) {
                    $this->logger->error('ConversationalCommerce: Voyage error – ' . $e->getMessage());
                    continue;
                }

                $vectors = [];
                $toLog   = [];
                foreach ($batchData as $j => $item) {
                    $p       = $item['product'];
                    $imgPath = $p->getImage();
                    $img     = ($imgPath && $imgPath !== 'no_selection')
                        ? $item['baseUrl'] . '/catalog/product' . $imgPath
                        : null;

                    $baseMeta = [
                        'product_id'   => (int)$p->getId(),
                        'sku'          => $p->getSku(),
                        'name'         => $p->getName(),
                        'price'        => (float)$p->getFinalPrice(),
                        'image_url'    => $img ?? '',
                        'product_url'  => $p->getProductUrl(),
                        'categories'   => implode(', ', $item['catNames']),
                        'short_desc'   => mb_substr(strip_tags((string)$p->getShortDescription()), 0, 500),
                        'description'  => mb_substr(strip_tags((string)$p->getDescription()), 0, 1000),
                        'options'      => $this->getConfigurableOptionsText($p),
                    ];
                    // Merge custom attributes — prefix with 'attr_' to avoid collisions
                    $attrLabelParts = [];
                    foreach ($item['customAttrs'] as $code => $labelValue) {
                        $baseMeta['attr_' . $code] = mb_substr((string)$labelValue['value'], 0, 500);
                        // Include the attr_ key in the label string so the LLM never has to guess it
                        $attrLabelParts[] = $labelValue['label'] . ' [attr_' . $code . ']: ' . $labelValue['value'];
                    }
                    // Human-readable label→value summary so the LLM can map labels to attr_ keys
                    if (!empty($attrLabelParts)) {
                        $baseMeta['attr_labels'] = mb_substr(implode(' | ', $attrLabelParts), 0, 2000);
                    }
                    $vectors[] = [
                        'id'       => 'product_' . $p->getId(),
                        'values'   => $embeddings[$j] ?? [],
                        'metadata' => $baseMeta,
                    ];
                    $toLog[] = [(int)$p->getId(), $p->getSku(), 'product_' . $p->getId(), $item['checksum'], $storeId];
                }

                if (!empty($vectors)) {
                    try {
                        $this->pinecone->upsert($vectors, $namespace);
                        foreach ($toLog as $args) {
                            $this->logIndexed(...$args);
                            $processed++;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('ConversationalCommerce: Pinecone upsert error – ' . $e->getMessage());
                    }
                }
            }

            if ($progress) {
                $progress($processed, $total);
            }

            $collection->clear();
            $page++;
        } while ($page <= ceil($total / 200));

        $this->logger->info(sprintf(
            'ConversationalCommerce: Indexed %d/%d products for store "%s".',
            $processed, $total, $store->getCode()
        ));
    }

    public function isSearchDegraded(): bool
    {
        return $this->termExtractor->isDegraded();
    }

    /**
     * Hybrid search: LLM term extraction → keyword (DB LIKE) + semantic (Pinecone) merged by SKU.
     *
     * Scoring:
     *   keyword-only match  → 1.50  (exact name/SKU hit, very reliable)
     *   keyword + vector    → vector_score + 0.50  (found in both sources)
     *   vector-only match   → vector_score  (0.0–1.0, semantic similarity)
     *
     * @param int $storeId  0 = use default store / global Pinecone namespace
     * @return array<int, array{score: float, source: string, metadata: array<string, mixed>}>
     */
    public function search(string $query, int $topK = 10, int $storeId = 0): array
    {
        $resolvedStoreId = $storeId > 0
            ? $storeId
            : (int)$this->storeManager->getDefaultStoreView()->getId();
        $namespace = 'store_' . $this->storeManager->getStore($resolvedStoreId)->getCode();

        // --- 1. Extract product-relevant terms from the query ---
        $terms = $this->termExtractor->extract($query, $resolvedStoreId);

        // --- 2. Keyword search using extracted terms ---
        $keywordResults = $this->catalogSearch->search($terms, $topK, $resolvedStoreId);

        // Index keyword results by SKU
        $merged = [];
        foreach ($keywordResults as $item) {
            $sku = $item['metadata']['sku'] ?? '';
            if ($sku) {
                $merged[$sku] = [
                    'score'    => 1.5,
                    'source'   => 'keyword',
                    'metadata' => $item['metadata'],
                ];
            }
        }

        // --- 3. Semantic vector search ---
        try {
            $vector  = $this->voyage->embed($query);
            $matches = $this->pinecone->query($vector, $topK, [], $namespace);

            foreach ($matches as $match) {
                $sku         = $match['metadata']['sku'] ?? '';
                $vectorScore = (float)($match['score'] ?? 0);

                if (!$sku) {
                    continue;
                }

                if (isset($merged[$sku])) {
                    // Found in both sources — boost score and merge metadata.
                    // Use keyword metadata as base (has live image_url from CatalogSearch),
                    // overlay only non-empty Pinecone fields (richer: description, categories).
                    $pineconeFields = array_filter(
                        $match['metadata'] ?? [],
                        static fn($v) => $v !== '' && $v !== null
                    );
                    $merged[$sku]['score']    = $vectorScore + 0.5;
                    $merged[$sku]['source']   = 'hybrid';
                    $merged[$sku]['metadata'] = array_merge(
                        $merged[$sku]['metadata'],
                        $pineconeFields
                    );
                } else {
                    $merged[$sku] = [
                        'score'    => $vectorScore,
                        'source'   => 'vector',
                        'metadata' => $match['metadata'] ?? [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Hybrid] Vector search failed, using keyword results only – ' . $e->getMessage());
        }

        // --- 4. Sort and trim ---
        uasort($merged, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice(array_values($merged), 0, $topK);

        $this->logger->info('[Hybrid] Search results', [
            'query'     => mb_substr($query, 0, 200),
            'store_id'  => $storeId,
            'namespace' => $namespace !== '' ? $namespace : '(global)',
            'hits'      => count($results),
            'results'   => array_map(fn($r) => [
                'name'   => $r['metadata']['name'] ?? '?',
                'sku'    => $r['metadata']['sku']  ?? '?',
                'cats'   => $r['metadata']['categories'] ?? '',
                'score'  => round($r['score'], 4),
                'source' => $r['source'],
            ], $results),
        ]);

        return $results;
    }

    /**
     * @param string[]                                   $catNames
     * @param array<string, array{label: string, value: string}> $customAttrs
     */
    private function buildEmbedText(
        \Magento\Catalog\Model\Product $p,
        array $catNames = [],
        array $customAttrs = []
    ): string {
        $parts   = [];
        $parts[] = 'Produktname: ' . $p->getName();
        $parts[] = 'SKU: ' . $p->getSku();

        if ($catNames) {
            $parts[] = 'Kategorien: ' . implode(', ', $catNames);
        }

        $price = $p->getFinalPrice();
        if ($price) {
            $parts[] = 'Preis: ' . number_format((float)$price, 2, ',', '.') . ' EUR';
        }

        $weight = $p->getData('weight');
        if ($weight !== null && $weight !== '' && (float)$weight > 0) {
            $parts[] = 'Gewicht: ' . number_format((float)$weight, 3, ',', '.') . ' kg';
        }

        $options = $this->getConfigurableOptionsText($p);
        if ($options) {
            $parts[] = 'Optionen: ' . $options;
        }

        // Custom attributes from the product's attribute set
        foreach ($customAttrs as $code => $labelValue) {
            $parts[] = $labelValue['label'] . ': ' . mb_substr($labelValue['value'], 0, 500);
        }

        $short = strip_tags((string)$p->getShortDescription());
        if ($short) {
            $parts[] = 'Kurzbeschreibung: ' . mb_substr($short, 0, 1000);
        }

        $desc = strip_tags((string)$p->getDescription());
        if ($desc) {
            $parts[] = 'Beschreibung: ' . mb_substr($desc, 0, 3000);
        }

        return implode("\n", $parts);
    }

    /**
     * Collects all non-empty custom attributes for a product, excluding system attributes
     * and any codes listed in the admin blacklist config.
     *
     * @return array<string, array{label: string, value: string}>  keyed by attribute_code
     */
    private function collectCustomAttributes(
        \Magento\Catalog\Model\Product $product,
        int $storeId
    ): array {
        $blacklist = $this->getAttributeBlacklist($storeId);

        // Core fields already handled explicitly — never duplicate them here
        $coreFields = [
            'name', 'sku', 'description', 'short_description', 'price',
            'special_price', 'weight', 'status', 'visibility',
        ];
        $skip = array_flip(array_merge($coreFields, self::SYSTEM_ATTRIBUTE_BLACKLIST, $blacklist));

        $result = [];
        foreach ($product->getAttributes() as $attribute) {
            $code = $attribute->getAttributeCode();
            if (isset($skip[$code])) {
                continue;
            }
            // Only include user-defined (non-system) attributes
            if (!$attribute->getIsUserDefined() && !in_array($attribute->getFrontendInput(), ['text', 'textarea', 'select', 'multiselect', 'boolean', 'price'], true)) {
                continue;
            }

            $rawValue = $product->getData($code);
            if ($rawValue === null || $rawValue === '' || $rawValue === false) {
                continue;
            }

            // Resolve option labels for select/multiselect
            $frontendInput = $attribute->getFrontendInput();
            if (in_array($frontendInput, ['select', 'multiselect'], true)) {
                try {
                    $label = $attribute->getSource()->getOptionText($rawValue);
                    if ($label === false || $label === null || $label === '') {
                        continue;
                    }
                    $displayValue = is_array($label) ? implode(', ', $label) : (string)$label;
                } catch (\Throwable) {
                    continue;
                }
            } elseif ($frontendInput === 'boolean') {
                $displayValue = $rawValue ? 'Ja' : 'Nein';
            } else {
                if (is_array($rawValue)) {
                    $displayValue = implode(', ', array_map('strval', $rawValue));
                } else {
                    $displayValue = strip_tags((string)$rawValue);
                }
                if ($displayValue === '' || $displayValue === '0') {
                    continue;
                }
            }

            $frontendLabel = $attribute->getStoreLabel($storeId) ?: $attribute->getFrontendLabel() ?: $code;

            $result[$code] = [
                'label' => (string)$frontendLabel,
                'value' => $displayValue,
            ];
        }

        return $result;
    }

    /**
     * @return string[]  Attribute codes to exclude, from admin config + system blacklist
     */
    private function getAttributeBlacklist(int $storeId): array
    {
        $scope   = $storeId > 0 ? ScopeInterface::SCOPE_STORE : \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scopeId = $storeId > 0 ? $storeId : null;
        $raw = (string)$this->config->getValue(
            'conversional_commerce/product_index/excluded_attributes',
            $scope,
            $scopeId
        );
        if ($raw === '') {
            return [];
        }
        return array_map('trim', explode(',', $raw));
    }

    /**
     * Returns a human-readable options string for configurable products, e.g.
     * "Color: Rot, Blau, Grün | Size: S, M, L, XL"
     *
     * Uses getConfigurableAttributesAsArray() to get only the options actually
     * assigned to this product's children — not all globally defined options.
     */
    private function getConfigurableOptionsText(\Magento\Catalog\Model\Product $product): string
    {
        if ($product->getTypeId() !== 'configurable') {
            return '';
        }
        try {
            $attributesArray = $this->configurableType->getConfigurableAttributesAsArray($product);
            $axisParts = [];
            foreach ($attributesArray as $attrData) {
                $label   = $attrData['store_label'] ?? $attrData['label'] ?? $attrData['attribute_code'] ?? '';
                $options = array_values(array_filter(array_column($attrData['values'] ?? [], 'label')));
                if ($options) {
                    $axisParts[] = $label . ': ' . implode(', ', $options);
                }
            }
            return implode(' | ', $axisParts);
        } catch (\Throwable $e) {
            $this->logger->warning('[ProductIndexer] getConfigurableOptionsText failed – ' . $e->getMessage());
            return '';
        }
    }

    /**
     * @param  int[]              $categoryIds
     * @param  array<int, string> $nameMap
     * @return string[]
     */
    private function resolveCategories(array $categoryIds, array $nameMap): array
    {
        return array_values(array_filter(
            array_map(fn($id) => $nameMap[(int)$id] ?? null, $categoryIds)
        ));
    }

    /**
     * @param int $storeId  Use store_id for store-specific names; 0 = admin/global scope
     * @return array<int, string>  category_id → name
     */
    private function loadCategoryNames(int $storeId = 0): array
    {
        $conn      = $this->resource->getConnection();
        $typeTable = $this->resource->getTableName('eav_entity_type');
        $attrTable = $this->resource->getTableName('eav_attribute');
        $valTable  = $this->resource->getTableName('catalog_category_entity_varchar');

        $attrId = $conn->fetchOne(
            "SELECT a.attribute_id FROM {$attrTable} a
             JOIN {$typeTable} t ON t.entity_type_id = a.entity_type_id
             WHERE t.entity_type_code = 'catalog_category' AND a.attribute_code = 'name'
             LIMIT 1"
        );

        if (!$attrId) {
            return [];
        }

        // Prefer store-specific names; fall back to store_id=0 (admin/global)
        $rows = $conn->fetchAll(
            "SELECT entity_id, value FROM {$valTable}
             WHERE attribute_id = ? AND store_id = 0",
            [(int)$attrId]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['entity_id']] = $row['value'];
        }

        if ($storeId > 0) {
            $storeRows = $conn->fetchAll(
                "SELECT entity_id, value FROM {$valTable}
                 WHERE attribute_id = ? AND store_id = ?",
                [(int)$attrId, $storeId]
            );
            foreach ($storeRows as $row) {
                $map[(int)$row['entity_id']] = $row['value'];
            }
        }

        return $map;
    }

    private function checksum(\Magento\Catalog\Model\Product $p): string
    {
        return md5($p->getName() . $p->getSku() . $p->getFinalPrice() . $p->getUpdatedAt() . $p->getAttributeSetId());
    }

    private function isAlreadyIndexed(int $productId, string $checksum, int $storeId = 0): bool
    {
        $table = $this->resource->getTableName('cc_product_index_log');
        $row   = $this->resource->getConnection()->fetchRow(
            'SELECT checksum FROM ' . $table . ' WHERE product_id = ? AND store_id = ?',
            [$productId, $storeId]
        );
        return $row && $row['checksum'] === $checksum;
    }

    private function logIndexed(int $productId, string $sku, string $pineconeId, string $checksum, int $storeId = 0): void
    {
        $table = $this->resource->getTableName('cc_product_index_log');
        $this->resource->getConnection()->insertOnDuplicate($table, [
            'product_id'   => $productId,
            'sku'          => $sku,
            'store_id'     => $storeId,
            'pinecone_id'  => $pineconeId,
            'checksum'     => $checksum,
            'indexed_at'   => date('Y-m-d H:i:s'),
        ], ['pinecone_id', 'checksum', 'indexed_at']);
    }
}
