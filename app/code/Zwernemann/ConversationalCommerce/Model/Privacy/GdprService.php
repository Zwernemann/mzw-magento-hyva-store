<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Privacy;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\GdprServiceInterface;

/**
 * GDPR Art. 17 implementation.
 *
 * Anonymization strategy:
 * - customer_email  → 'anon-<sha256[:12]>@deleted.invalid'  (one-way hash, no reverse lookup)
 * - magento_customer_id → NULL
 * - conversation status → 'anonymized'
 * - message content_text / content_html → deletion notice
 * - message intent_data → NULL  (may contain PII from RAG context)
 * - cc_customer_alias_email rows for this email → deleted
 * - cc_llm_usage_log rows linked to affected conversations → conversation_id set to NULL
 *
 * The conversation row itself is retained for operational metrics
 * (conversion rates etc.) but contains no personal data after anonymization.
 */
class GdprService implements GdprServiceInterface
{
    private const DELETION_TEXT = '[Inhalt auf DSGVO-Anfrage (Art. 17) gelöscht]';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface    $logger
    ) {}

    public function anonymizeByEmail(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }

        $conn      = $this->resourceConnection->getConnection();
        $convTable = $conn->getTableName('cc_conversation');
        $msgTable  = $conn->getTableName('cc_conversation_message');
        $aliasTable= $conn->getTableName('cc_customer_alias_email');
        $logTable  = $conn->getTableName('cc_llm_usage_log');

        // Collect all affected conversation IDs before we overwrite the email
        $conversationIds = $conn->fetchCol(
            $conn->select()->from($convTable, ['id'])->where('customer_email = ?', $email)
        );

        if (empty($conversationIds)) {
            $this->logger->info('[GDPR] No conversations found for email: ' . $email);
            return 0;
        }

        $anonEmail = 'anon-' . substr(hash('sha256', $email), 0, 12) . '@deleted.invalid';

        $conn->beginTransaction();
        try {
            // 1. Anonymize conversation rows
            $conn->update($convTable, [
                'customer_email'      => $anonEmail,
                'magento_customer_id' => null,
                'status'              => 'anonymized',
            ], ['id IN (?)' => $conversationIds]);

            // 2. Wipe message content
            $conn->update($msgTable, [
                'content_text' => self::DELETION_TEXT,
                'content_html' => null,
                'intent_data'  => null,
            ], ['conversation_id IN (?)' => $conversationIds]);

            // 3. Remove alias emails
            $conn->delete($aliasTable, ['email = ?' => $email]);

            // 4. Detach usage log rows (metrics preserved, link to PII removed)
            $conn->update($logTable, [
                'conversation_id' => null,
            ], ['conversation_id IN (?)' => $conversationIds]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error('[GDPR] Anonymization failed for ' . $email . ': ' . $e->getMessage());
            throw $e;
        }

        $count = count($conversationIds);
        $this->logger->info(sprintf('[GDPR] Anonymized %d conversation(s) for %s → %s', $count, $email, $anonEmail));
        return $count;
    }

    public function anonymizeByConversationId(int $conversationId): bool
    {
        $conn      = $this->resourceConnection->getConnection();
        $convTable = $conn->getTableName('cc_conversation');
        $msgTable  = $conn->getTableName('cc_conversation_message');
        $logTable  = $conn->getTableName('cc_llm_usage_log');

        $row = $conn->fetchRow(
            $conn->select()->from($convTable)->where('id = ?', $conversationId)
        );
        if (!$row) {
            return false;
        }

        $email     = (string)($row['customer_email'] ?? '');
        $anonEmail = 'anon-' . substr(hash('sha256', $email), 0, 12) . '@deleted.invalid';

        $conn->beginTransaction();
        try {
            $conn->update($convTable, [
                'customer_email'      => $anonEmail,
                'magento_customer_id' => null,
                'status'              => 'anonymized',
            ], ['id = ?' => $conversationId]);

            $conn->update($msgTable, [
                'content_text' => self::DELETION_TEXT,
                'content_html' => null,
                'intent_data'  => null,
            ], ['conversation_id = ?' => $conversationId]);

            $conn->update($logTable, [
                'conversation_id' => null,
            ], ['conversation_id = ?' => $conversationId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error('[GDPR] Anonymization failed for conversation #' . $conversationId . ': ' . $e->getMessage());
            throw $e;
        }

        $this->logger->info(sprintf('[GDPR] Anonymized conversation #%d (%s → %s)', $conversationId, $email, $anonEmail));
        return true;
    }
}
