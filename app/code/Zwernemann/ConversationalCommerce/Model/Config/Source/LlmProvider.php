<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LlmProvider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'anthropic', 'label' => 'Anthropic Claude (Standard)'],
            ['value' => 'mistral',   'label' => 'Mistral AI (DSGVO / EU)'],
        ];
    }
}
