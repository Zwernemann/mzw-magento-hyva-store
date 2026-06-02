<?php
declare(strict_types=1);

namespace Demo\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the "Lacke" and "Elektro" attribute sets as clones of the Default set
 * (which already carries the demo attributes added by AddCatalogAttributes).
 */
class CreateAttributeSets implements DataPatchInterface
{
    private const SETS = ['Lacke', 'Elektro'];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CategorySetupFactory $categorySetupFactory,
        private readonly AttributeSetFactory $attributeSetFactory,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository
    ) {
    }

    public static function getDependencies(): array
    {
        return [AddCatalogAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $categorySetup = $this->categorySetupFactory->create(['setup' => $this->moduleDataSetup]);
        $entityTypeId  = (int) $categorySetup->getEntityTypeId(Product::ENTITY);
        $defaultSetId  = (int) $categorySetup->getDefaultAttributeSetId($entityTypeId);

        $sortOrder = 100;
        foreach (self::SETS as $name) {
            if ($this->setExists($name, $entityTypeId)) {
                continue;
            }
            $set = $this->attributeSetFactory->create();
            $set->setData([
                'attribute_set_name' => $name,
                'entity_type_id'     => $entityTypeId,
                'sort_order'         => $sortOrder += 10,
            ]);
            $set->validate();
            $this->attributeSetRepository->save($set);
            $set->initFromSkeleton($defaultSetId);
            $this->attributeSetRepository->save($set);
        }

        return $this;
    }

    private function setExists(string $name, int $entityTypeId): bool
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('eav_attribute_set');
        $select = $connection->select()
            ->from($table, 'attribute_set_id')
            ->where('attribute_set_name = ?', $name)
            ->where('entity_type_id = ?', $entityTypeId);

        return (bool) $connection->fetchOne($select);
    }
}
