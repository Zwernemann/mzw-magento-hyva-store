<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ConversationMessage extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('cc_conversation_message', 'id');
    }

    public function messageIdExists(string $messageId): bool
    {
        $connection = $this->getConnection();
        $select     = $connection->select()
            ->from($this->getMainTable(), ['id'])
            ->where('message_id = ?', $messageId)
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param  string $order 'ASC' (oldest-first, default) or 'DESC' (newest-first)
     * @return array<int, array<string, mixed>>
     */
    public function getMessagesByConversationId(int $conversationId, int $limit = 20, string $order = 'ASC'): array
    {
        $connection = $this->getConnection();
        $select     = $connection->select()
            ->from($this->getMainTable())
            ->where('conversation_id = ?', $conversationId)
            ->order('created_at ' . ($order === 'DESC' ? 'DESC' : 'ASC'))
            ->limit($limit);

        return $connection->fetchAll($select);
    }
}
