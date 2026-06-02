<?php
declare(strict_types=1);

namespace Demo\Catalog\Setup\Patch\Data;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

/**
 * Builds the Lacke and Elektro category trees, modelled on alles-im-lackshop.de
 * and elektro-pepi.de. Idempotent: existing categories (matched by name + parent)
 * are reused, so the patch can be re-run safely while iterating.
 */
class CreateCategoryTree implements DataPatchInterface
{
    private const DEFAULT_ROOT_CATEGORY_ID = 2;

    /** Nested tree: name => [children...] (leaf = empty array). */
    private const TREE = [
        'Lacke' => [
            'Lacke Spraydosen' => [
                'Autolack Lackierpistole' => [],
                'Autolack Spraydosen' => [],
                'Lackstift Set' => [],
                'Motorradlacke' => [],
                'Maler- & Industrielacke' => [],
                'Spraydosen' => [],
                'Klarlack' => [],
                'Hitzebeständige Lacke' => [],
                'Neonlack' => [],
                'Sonderlacke' => [],
            ],
            'Füller & Grundierung' => [],
            'Werkstatt' => [
                'Lösemittel' => [],
                'Technische Sprays' => [],
                'Schleifen' => [],
                'Nassschleifen' => [],
                'Hygiene' => [],
                'Verbrauchsartikel' => [],
                'Spachtel' => [],
                'Unterbodenschutz' => [],
            ],
            'Reinigung' => [],
            'Maschinen & Werkzeuge' => [
                'Maschinen' => [],
                'Werkzeuge' => [],
                'Lackierpistolen' => [],
            ],
            'Autopflege' => [],
            'Standox' => [
                'Autolack' => [],
                'Motorradlack' => [],
                'Nutzfahrzeuglack' => [],
                'Mischlacke' => [],
                'Grundierung & Füller' => [],
                'Klarlack' => [],
                'Zubehör' => [],
            ],
        ],
        'Elektro' => [
            'Balkonkraftwerke' => [],
            'Energy' => [
                'Stromspeicher & Solarpaneele' => [],
                'Solartechnik' => [],
            ],
            'Schalterprogramme' => [
                'Busch-Jaeger' => [],
                'Gira' => [],
                'Jung' => [],
                'Berker' => [],
                'Siemens' => [],
                'Merten' => [],
                'Legrand' => [],
                'ABB' => [],
                'Kopp' => [],
                'Schrack' => [],
                'TEM' => [],
                'Solera' => [],
            ],
            'Elektroinstallation' => [
                'Steckvorrichtungen & Verlängerungen' => [],
                'Verteiler & Dosen' => [],
                'Verteiler-Einbauten' => [],
                'Werkzeuge' => [],
                'SAT, Antenne & Multimedia' => [],
                'OBO Schnellverlegesysteme' => [],
            ],
            'Türsprechanlagen' => [
                'Gong' => [],
                'Audiosprechanlagen' => [],
                'Videosprechanlagen' => [],
                'Türöffner' => [],
            ],
            'Sicherheit' => [
                'Kameras' => [],
                'Kamera-Dummys' => [],
                'Alarm' => [],
                'Frühwarnsysteme' => [],
            ],
            'Hausautomatisation' => [],
            'Beleuchtung' => [
                'Taschenlampen' => [],
                'LED-Leuchtmittel' => [],
                'Außenbeleuchtung' => [],
            ],
            'Elektrogeräte' => [
                'Luftreiniger' => [],
                'Smarte Elektrogeräte' => [],
                'Sonstiges' => [],
            ],
            'E-Mobilität' => [],
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $this->createNodes(self::TREE, self::DEFAULT_ROOT_CATEGORY_ID, 1);
        $this->repairPaths();
        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Recompute path + level for every category from the parent_id graph.
     * Guards against any stale/incorrect path data and is safe to re-run.
     */
    private function repairPaths(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('catalog_category_entity');
        $rows = $connection->fetchPairs(
            $connection->select()->from($table, ['entity_id', 'parent_id'])
        );

        $cache = [];
        $resolve = function (int $id) use (&$resolve, $rows, &$cache): string {
            if (isset($cache[$id])) {
                return $cache[$id];
            }
            if ($id <= 1 || !isset($rows[$id]) || (int) $rows[$id] === 0) {
                return $cache[$id] = (string) $id;
            }
            return $cache[$id] = $resolve((int) $rows[$id]) . '/' . $id;
        };

        foreach ($rows as $id => $parentId) {
            $id = (int) $id;
            if ($id <= 2) {
                continue;
            }
            $path = $resolve($id);
            $connection->update(
                $table,
                ['path' => $path, 'level' => substr_count($path, '/')],
                ['entity_id = ?' => $id]
            );
        }
    }

    private function createNodes(array $nodes, int $parentId, int $level): void
    {
        $position = 1;
        foreach ($nodes as $name => $children) {
            $categoryId = $this->getOrCreateCategory($name, $parentId, $position++);
            if (!empty($children)) {
                $this->createNodes($children, $categoryId, $level + 1);
            }
        }
    }

    private function getOrCreateCategory(string $name, int $parentId, int $position): int
    {
        $existingId = $this->findChildByName($name, $parentId);
        if ($existingId !== null) {
            return $existingId;
        }

        $parent = $this->categoryRepository->get($parentId);

        $category = $this->categoryFactory->create();
        $category->setName($name)
            ->setParentId($parentId)
            ->setPath($parent->getPath())
            ->setIsActive(true)
            ->setIncludeInMenu(true)
            ->setPosition($position)
            ->setStoreId(Store::DEFAULT_STORE_ID)
            ->setAttributeSetId($category->getDefaultAttributeSetId())
            ->setDisplayMode('PRODUCTS');

        $category = $this->categoryRepository->save($category);

        return (int) $category->getId();
    }

    private function findChildByName(string $name, int $parentId): ?int
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToFilter('parent_id', $parentId)
            ->addAttributeToFilter('name', $name)
            ->setPageSize(1);

        $category = $collection->getFirstItem();

        return $category->getId() ? (int) $category->getId() : null;
    }
}
