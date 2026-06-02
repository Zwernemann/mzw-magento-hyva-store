<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Api;

/**
 * GDPR data erasure service (Art. 17 DSGVO – Recht auf Löschung).
 *
 * Anonymizes all conversation data associated with a customer.
 * The email address is replaced with an irreversible hash, message
 * content is overwritten with a deletion notice, and alias addresses
 * are removed. The conversation shell (metadata) is kept for
 * audit purposes; personal data is expunged.
 */
interface GdprServiceInterface
{
    /**
     * Anonymize all conversations and messages for the given customer email.
     *
     * @param string $email Customer email to erase
     * @return int Number of conversations anonymized
     */
    public function anonymizeByEmail(string $email): int;

    /**
     * Anonymize a single conversation identified by its DB id.
     *
     * @param int $conversationId
     * @return bool True if found and anonymized, false if not found
     */
    public function anonymizeByConversationId(int $conversationId): bool;
}
