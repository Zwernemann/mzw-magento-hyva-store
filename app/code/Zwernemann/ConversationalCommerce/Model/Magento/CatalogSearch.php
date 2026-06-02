<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Magento;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\PipelineLogger;

/**
 * Keyword-based product search using LIKE filters on name and SKU.
 * Receives pre-extracted search terms (from SearchTermExtractor) so it has
 * no language dependency. Works on every Magento setup.
 */
class CatalogSearch
{
    public function __construct(
        private readonly CollectionFactory     $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
        private readonly PipelineLogger        $pipelineLogger
    ) {}

    /**
     * @param  string[] $terms    Pre-extracted product terms (e.g. ["Prosonic", "brochures"])
     * @param  int      $storeId  Magento store ID to scope the search (0 = default store)
     * @return array<int, array{score: float, source: string, metadata: array<string, mixed>}>
     */
    public function search(array $terms, int $limit = 10, int $storeId = 0): array
    {
        if (empty($terms)) {
            return [];
        }

        $this->pipelineLogger->section('CATALOG KEYWORD SEARCH (DB LIKE)');
        $this->pipelineLogger->data('Terms', $terms);

        try {
            $storeId = $storeId > 0
                ? $storeId
                : (int)$this->storeManager->getDefaultStoreView()->getId();

            $collection = $this->collectionFactory->create();
            $collection->setStore($storeId);
            $collection->addWebsiteFilter($this->storeManager->getStore($storeId)->getWebsiteId());
            $collection->addAttributeToSelect(['name', 'sku', 'short_description', 'price', 'image']);
            $collection->addAttributeToFilter('status', 1);
            $collection->addAttributeToFilter('visibility', ['neq' => 1]);

            // OR across all terms × (name, sku)
            $conditions = [];
            foreach ($terms as $term) {
                $escaped      = addcslashes($term, '%_\\');
                $conditions[] = ['attribute' => 'name', 'like' => '%' . $escaped . '%'];
                $conditions[] = ['attribute' => 'sku',  'like' => '%' . $escaped . '%'];
            }
            $this->pipelineLogger->data('SQL filter conditions (OR)', $conditions);
            $collection->addAttributeToFilter($conditions);
            $collection->setPageSize($limit);
            $collection->load();

            $mediaBase = rtrim(
                $this->storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            );

            $results = [];
            foreach ($collection as $product) {
                $imgPath  = $product->getImage();
                $imageUrl = ($imgPath && $imgPath !== 'no_selection')
                    ? $mediaBase . '/catalog/product' . $imgPath
                    : '';

                $results[] = [
                    'score'    => 1.0,
                    'source'   => 'keyword',
                    'metadata' => [
                        'product_id' => (int)$product->getId(),
                        'sku'        => (string)$product->getSku(),
                        'name'       => (string)$product->getName(),
                        'price'      => (float)$product->getFinalPrice(),
                        'image_url'  => $imageUrl,
                        'categories' => '',
                        'short_desc' => mb_substr(
                            strip_tags((string)$product->getShortDescription()), 0, 500
                        ),
                    ],
                ];
            }

            $this->pipelineLogger->data('Keyword search results (' . count($results) . ' hits)', $results);
            $this->logger->info('[Catalog] Keyword search', [
                'terms'   => $terms,
                'hits'    => count($results),
                'results' => array_map(
                    fn($r) => ['name' => $r['metadata']['name'], 'sku' => $r['metadata']['sku']],
                    $results
                ),
            ]);

            return $results;

        } catch (\Throwable $e) {
            $this->logger->warning('[Catalog] Keyword search failed – ' . $e->getMessage());
            return [];
        }
    }
}
