<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UpgradeSchemaInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.1.0', '<')) {
            $this->createCustomerAliasEmailTable($setup);
        }

        if (version_compare($context->getVersion(), '1.4.0', '<')) {
            $this->addStoreIdToConversations($setup);
            $this->addStoreIdToProductIndexLog($setup);
        }

        if (version_compare($context->getVersion(), '1.6.0', '<')) {
            $this->createLlmUsageLogTable($setup);
        }

        if (version_compare($context->getVersion(), '1.7.0', '<')) {
            $this->addEscalationColumnsToConversations($setup);
        }

        $setup->endSetup();
    }

    private function createCustomerAliasEmailTable(SchemaSetupInterface $setup): void
    {
        $table = $setup->getConnection()->newTable(
            $setup->getTable('cc_customer_alias_email')
        )
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('customer_id', Table::TYPE_INTEGER, null, [
            'nullable' => false, 'unsigned' => true,
        ], 'Magento customer entity_id')
        ->addColumn('email', Table::TYPE_TEXT, 255, [
            'nullable' => false,
        ], 'Alias email address (lower-cased on insert)')
        ->addColumn('label', Table::TYPE_TEXT, 255, [
            'nullable' => true,
        ], 'Optional description (e.g. "Work laptop", "Colleague")')
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addIndex(
            $setup->getIdxName('cc_customer_alias_email', ['email'], AdapterInterface::INDEX_TYPE_UNIQUE),
            ['email'],
            ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
        )
        ->addIndex(
            $setup->getIdxName('cc_customer_alias_email', ['customer_id']),
            ['customer_id']
        )
        ->setComment('Conversational Commerce – Additional customer email addresses for sender authentication');

        $setup->getConnection()->createTable($table);
    }

    private function addStoreIdToConversations(SchemaSetupInterface $setup): void
    {
        $conn  = $setup->getConnection();
        $table = $setup->getTable('cc_conversation');

        if ($conn->tableColumnExists($table, 'store_id')) {
            return;
        }

        $conn->addColumn($table, 'store_id', [
            'type'     => Table::TYPE_SMALLINT,
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
            'comment'  => 'Magento store_id — 0 = default/fallback',
        ]);

        $conn->addIndex(
            $table,
            $setup->getIdxName($table, ['store_id']),
            ['store_id']
        );
    }

    private function createLlmUsageLogTable(SchemaSetupInterface $setup): void
    {
        $table = $setup->getConnection()->newTable(
            $setup->getTable('cc_llm_usage_log')
        )
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('conversation_id', Table::TYPE_INTEGER, null, [
            'nullable' => true, 'unsigned' => true,
        ])
        ->addColumn('channel_type', Table::TYPE_TEXT, 50, ['nullable' => false, 'default' => ''])
        ->addColumn('provider', Table::TYPE_TEXT, 20, ['nullable' => false, 'default' => ''])
        ->addColumn('model', Table::TYPE_TEXT, 100, ['nullable' => false, 'default' => ''])
        ->addColumn('input_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('output_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('cache_write_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('cache_read_tokens', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true, 'default' => 0])
        ->addColumn('cost_usd', Table::TYPE_DECIMAL, '10,6', ['nullable' => false, 'default' => '0.000000'])
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addIndex($setup->getIdxName('cc_llm_usage_log', ['conversation_id']), ['conversation_id'])
        ->addIndex($setup->getIdxName('cc_llm_usage_log', ['channel_type']), ['channel_type'])
        ->addIndex($setup->getIdxName('cc_llm_usage_log', ['created_at']), ['created_at'])
        ->setComment('Conversational Commerce – LLM API usage and cost log');

        $setup->getConnection()->createTable($table);
    }

    private function addEscalationColumnsToConversations(SchemaSetupInterface $setup): void
    {
        $conn  = $setup->getConnection();
        $table = $setup->getTable('cc_conversation');

        $columns = [
            'escalation_reason'      => ['type' => Table::TYPE_TEXT, 'length' => 255, 'nullable' => true, 'comment' => 'Human-readable escalation reason'],
            'escalated_at'           => ['type' => Table::TYPE_TIMESTAMP, 'length' => null, 'nullable' => true, 'comment' => 'Timestamp when escalation was triggered'],
            'escalation_approved_at' => ['type' => Table::TYPE_TIMESTAMP, 'length' => null, 'nullable' => true, 'comment' => 'Timestamp when admin approved/released the escalation'],
            'escalation_approved_by' => ['type' => Table::TYPE_TEXT, 'length' => 255, 'nullable' => true, 'comment' => 'Admin email who approved'],
        ];

        foreach ($columns as $name => $def) {
            if (!$conn->tableColumnExists($table, $name)) {
                $conn->addColumn($table, $name, $def);
            }
        }
    }

    private function addStoreIdToProductIndexLog(SchemaSetupInterface $setup): void
    {
        $conn  = $setup->getConnection();
        $table = $setup->getTable('cc_product_index_log');

        if ($conn->tableColumnExists($table, 'store_id')) {
            return;
        }

        // Drop the old single-column unique index on product_id so we can replace it
        // with a composite unique index on (product_id, store_id).
        $oldIndexName = $setup->getIdxName(
            $table,
            ['product_id'],
            AdapterInterface::INDEX_TYPE_UNIQUE
        );
        $existingIndexes = $conn->getIndexList($table);
        if (isset($existingIndexes[strtoupper($oldIndexName)])) {
            $conn->dropIndex($table, $oldIndexName);
        }

        $conn->addColumn($table, 'store_id', [
            'type'     => Table::TYPE_SMALLINT,
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
            'comment'  => 'Magento store_id for Pinecone namespace isolation',
        ]);

        $conn->addIndex(
            $table,
            $setup->getIdxName($table, ['product_id', 'store_id'], AdapterInterface::INDEX_TYPE_UNIQUE),
            ['product_id', 'store_id'],
            AdapterInterface::INDEX_TYPE_UNIQUE
        );
    }
}
