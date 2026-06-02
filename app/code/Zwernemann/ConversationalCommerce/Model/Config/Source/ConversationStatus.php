<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ConversationStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'open',      'label' => __('Offen')],
            ['value' => 'pending',   'label' => __('Ausstehend')],
            ['value' => 'escalated', 'label' => __('Eskaliert')],
            ['value' => 'resolved',  'label' => __('Abgeschlossen')],
        ];
    }
}
