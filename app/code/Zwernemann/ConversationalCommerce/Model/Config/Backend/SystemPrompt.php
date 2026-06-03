<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Prevents empty saves from overriding the config.xml default in core_config_data.
 * When the admin clears the field and saves, Magento would normally write an empty
 * string to core_config_data, making ScopeConfigInterface::getValue() return '' instead
 * of the config.xml default. This model deletes that row immediately after save so the
 * config.xml default is always the fallback when no custom prompt is configured.
 */
class SystemPrompt extends Value
{
    public function afterSave(): static
    {
        parent::afterSave();
        if (trim((string)$this->getValue()) === '') {
            $this->_resource->delete($this);
        }
        return $this;
    }
}
