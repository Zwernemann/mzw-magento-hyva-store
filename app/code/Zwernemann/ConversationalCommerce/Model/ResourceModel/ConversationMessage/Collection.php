<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\ResourceModel\ConversationMessage;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Zwernemann\ConversationalCommerce\Model\ConversationMessage;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\ConversationMessage as MessageResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ConversationMessage::class, MessageResource::class);
    }
}
