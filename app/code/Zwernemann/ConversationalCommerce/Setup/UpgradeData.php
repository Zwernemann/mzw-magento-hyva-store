<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;

class UpgradeData implements UpgradeDataInterface
{
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.2.0', '<')) {
            $this->migrateSystemPromptPath($setup);
        }

        if (version_compare($context->getVersion(), '1.3.0', '<')) {
            $this->migrateHistoryMaxCharsPath($setup);
        }

        $setup->endSetup();
    }

    /**
     * Copy existing conversional_commerce/webchat/history_message_max_chars values to
     * conversional_commerce/llm/history_message_max_chars so operator customisations are
     * preserved after the config path was moved from the WebChat group to the LLM group.
     */
    private function migrateHistoryMaxCharsPath(ModuleDataSetupInterface $setup): void
    {
        $connection = $setup->getConnection();
        $table      = $setup->getTable('core_config_data');

        $connection->query(
            'INSERT IGNORE INTO ' . $table . ' (scope, scope_id, path, value) '
            . 'SELECT scope, scope_id, \'conversional_commerce/llm/history_message_max_chars\', value '
            . 'FROM ' . $table . ' '
            . 'WHERE path = \'conversional_commerce/webchat/history_message_max_chars\''
        );
    }

    /**
     * Copy existing conversional_commerce/anthropic/system_prompt values to
     * conversional_commerce/llm/system_prompt so operator customisations are preserved
     * after the config path rename.
     *
     * Uses INSERT IGNORE so any destination row already set by the operator is kept intact.
     */
    private function migrateSystemPromptPath(ModuleDataSetupInterface $setup): void
    {
        $connection = $setup->getConnection();
        $table      = $setup->getTable('core_config_data');

        $connection->query(
            'INSERT IGNORE INTO ' . $table . ' (scope, scope_id, path, value) '
            . 'SELECT scope, scope_id, \'conversional_commerce/llm/system_prompt\', value '
            . 'FROM ' . $table . ' '
            . 'WHERE path = \'conversional_commerce/anthropic/system_prompt\''
        );
    }
}
