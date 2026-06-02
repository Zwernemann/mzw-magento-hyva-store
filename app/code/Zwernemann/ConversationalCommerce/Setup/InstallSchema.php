<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $this->createConversationsTable($setup);
        $this->createConversationMessagesTable($setup);
        $this->createProductIndexLogTable($setup);

        $setup->endSetup();
    }

    private function createConversationsTable(SchemaSetupInterface $setup): void
    {
        $table = $setup->getConnection()->newTable(
            $setup->getTable('cc_conversation')
        )
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('session_id', Table::TYPE_TEXT, 255, ['nullable' => false])
        ->addColumn('channel_type', Table::TYPE_TEXT, 50, ['nullable' => false, 'default' => 'email'])
        ->addColumn('customer_email', Table::TYPE_TEXT, 255, ['nullable' => false])
        ->addColumn('magento_customer_id', Table::TYPE_INTEGER, null, ['nullable' => true, 'unsigned' => true])
        ->addColumn('status', Table::TYPE_TEXT, 20, ['nullable' => false, 'default' => 'open'])
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addColumn('updated_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE,
        ])
        ->addIndex($setup->getIdxName('cc_conversation', ['session_id']), ['session_id'])
        ->addIndex($setup->getIdxName('cc_conversation', ['customer_email']), ['customer_email'])
        ->addIndex($setup->getIdxName('cc_conversation', ['status']), ['status'])
        ->setComment('Conversational Commerce – Conversations');

        $setup->getConnection()->createTable($table);
    }

    private function createConversationMessagesTable(SchemaSetupInterface $setup): void
    {
        $table = $setup->getConnection()->newTable(
            $setup->getTable('cc_conversation_message')
        )
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('conversation_id', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true])
        ->addColumn('direction', Table::TYPE_TEXT, 10, ['nullable' => false])  // 'inbound' | 'outbound'
        ->addColumn('channel_type', Table::TYPE_TEXT, 50, ['nullable' => false])
        ->addColumn('message_id', Table::TYPE_TEXT, 255, ['nullable' => true])
        ->addColumn('content_text', Table::TYPE_TEXT, '64k', ['nullable' => false])
        ->addColumn('content_html', Table::TYPE_TEXT, '64k', ['nullable' => true])
        ->addColumn('intent', Table::TYPE_TEXT, 50, ['nullable' => true])
        ->addColumn('intent_data', Table::TYPE_TEXT, '64k', ['nullable' => true])  // JSON
        ->addColumn('order_id', Table::TYPE_INTEGER, null, ['nullable' => true, 'unsigned' => true])
        ->addColumn('created_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addIndex(
            $setup->getIdxName('cc_conversation_message', ['conversation_id']),
            ['conversation_id']
        )
        ->addForeignKey(
            $setup->getFkName('cc_conversation_message', 'conversation_id', 'cc_conversation', 'id'),
            'conversation_id',
            $setup->getTable('cc_conversation'),
            'id',
            Table::ACTION_CASCADE
        )
        ->setComment('Conversational Commerce – Messages per Conversation');

        $setup->getConnection()->createTable($table);
    }

    private function createProductIndexLogTable(SchemaSetupInterface $setup): void
    {
        $table = $setup->getConnection()->newTable(
            $setup->getTable('cc_product_index_log')
        )
        ->addColumn('id', Table::TYPE_INTEGER, null, [
            'identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true,
        ])
        ->addColumn('product_id', Table::TYPE_INTEGER, null, ['nullable' => false, 'unsigned' => true])
        ->addColumn('sku', Table::TYPE_TEXT, 64, ['nullable' => false])
        ->addColumn('pinecone_id', Table::TYPE_TEXT, 255, ['nullable' => true])
        ->addColumn('indexed_at', Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Table::TIMESTAMP_INIT,
        ])
        ->addColumn('checksum', Table::TYPE_TEXT, 64, ['nullable' => true])
        ->addIndex(
            $setup->getIdxName('cc_product_index_log', ['product_id'], \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE),
            ['product_id'],
            ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
        )
        ->setComment('Conversational Commerce – Product Pinecone Index Log');

        $setup->getConnection()->createTable($table);
    }
}
