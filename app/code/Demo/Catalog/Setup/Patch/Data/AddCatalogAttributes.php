<?php
declare(strict_types=1);

namespace Demo\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the product attributes used by the Lacke and Elektro demo catalog.
 *
 * Select attributes are global-scope so they can be used for configurable products;
 * text attributes carry technical specs and are shown on the product page.
 */
class AddCatalogAttributes implements DataPatchInterface
{
    /** Select attributes: code => [label, group, options[]] */
    private const SELECT_ATTRIBUTES = [
        'farbe' => [
            'Farbe',
            'Lack-Eigenschaften',
            ['Schwarz', 'Weiß', 'Rot', 'Blau', 'Grün', 'Gelb', 'Silber', 'Grau',
             'Orange', 'Anthrazit', 'Metallic-Silber', 'Klar/Transparent'],
        ],
        'glanzgrad' => [
            'Glanzgrad',
            'Lack-Eigenschaften',
            ['Hochglänzend', 'Glänzend', 'Seidenglanz', 'Seidenmatt', 'Matt', 'Stumpfmatt'],
        ],
        'gebindegroesse' => [
            'Gebindegröße',
            'Lack-Eigenschaften',
            ['Lackstift 12 ml', 'Spraydose 400 ml', '1 Liter', '2,5 Liter', '5 Liter', '10 Liter'],
        ],
        'hersteller' => [
            'Hersteller',
            'Lack-Eigenschaften',
            ['Standox', 'Spies Hecker', 'Glasurit', 'MIPA', 'Presto', 'Nigrin', 'Demo Coatings'],
        ],
        'oberflaeche' => [
            'Oberfläche / Effekt',
            'Lack-Eigenschaften',
            ['Uni', 'Metallic', 'Perleffekt', 'Struktur', 'Neon'],
        ],
        'hersteller_serie' => [
            'Hersteller / Serie',
            'Elektro-Eigenschaften',
            ['Busch-Jaeger', 'Gira', 'Jung', 'Berker', 'Siemens', 'Merten', 'Legrand',
             'ABB', 'Kopp', 'Schrack', 'TEM', 'Solera'],
        ],
    ];

    /** Text attributes: code => [label, group] */
    private const TEXT_ATTRIBUTES = [
        'farbton_ral'   => ['RAL-Farbton', 'Lack-Eigenschaften'],
        'spannung'      => ['Spannung', 'Elektro-Eigenschaften'],
        'leistung_watt' => ['Leistung (W)', 'Elektro-Eigenschaften'],
        'schutzart_ip'  => ['Schutzart (IP)', 'Elektro-Eigenschaften'],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
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
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::SELECT_ATTRIBUTES as $code => [$label, $group, $options]) {
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type'                    => 'int',
                'label'                   => $label,
                'input'                   => 'select',
                'source'                  => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'required'                => false,
                'user_defined'            => true,
                'searchable'              => true,
                'filterable'              => true,
                'comparable'              => true,
                'visible_on_front'        => true,
                'used_in_product_listing' => true,
                'is_used_in_grid'         => true,
                'group'                   => $group,
                'option'                  => ['values' => $options],
            ]);
        }

        foreach (self::TEXT_ATTRIBUTES as $code => [$label, $group]) {
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type'                    => 'varchar',
                'label'                   => $label,
                'input'                   => 'text',
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'required'                => false,
                'user_defined'            => true,
                'searchable'              => true,
                'filterable'              => false,
                'comparable'              => true,
                'visible_on_front'        => true,
                'used_in_product_listing' => true,
                'group'                   => $group,
            ]);
        }

        return $this;
    }
}
