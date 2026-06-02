<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AnthropicModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6  (schnell, günstig)'],
            ['value' => 'claude-opus-4-7',   'label' => 'Claude Opus 4.7    (leistungsstark, teurer)'],
            ['value' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5  (sehr schnell, sehr günstig)'],
        ];
    }
}
