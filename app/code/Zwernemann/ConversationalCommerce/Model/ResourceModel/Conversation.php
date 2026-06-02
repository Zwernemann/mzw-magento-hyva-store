<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Conversation extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('cc_conversation', 'id');
    }

    public function loadBySessionId(\Magento\Framework\Model\AbstractModel $object, string $sessionId, int $storeId = 0): self
    {
        $connection = $this->getConnection();
        $select     = $connection->select()
            ->from($this->getMainTable())
            ->where('session_id = ?', $sessionId);

        if ($storeId > 0) {
            $select->where('store_id = ?', $storeId);
        }

        $select->limit(1);

        $data = $connection->fetchRow($select);
        if ($data) {
            $object->setData($data);
        }
        $this->_afterLoad($object);
        return $this;
    }
}
