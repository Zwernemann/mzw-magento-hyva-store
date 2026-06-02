<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class MistralModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'mistral-large-latest', 'label' => 'Mistral Large  (leistungsstark)'],
            ['value' => 'mistral-small-latest', 'label' => 'Mistral Small  (schnell, günstig)'],
            ['value' => 'ministral-8b-latest',  'label' => 'Ministral 8B   (sehr schnell)'],
            ['value' => 'ministral-3b-latest',  'label' => 'Ministral 3B   (minimal, sehr günstig)'],
        ];
    }
}
