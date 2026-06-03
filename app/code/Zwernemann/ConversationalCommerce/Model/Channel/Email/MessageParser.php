<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Channel\Email;

use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;
use Zwernemann\ConversationalCommerce\Model\Message\UnifiedMessage;

/**
 * Converts raw IMAP message data into a UnifiedMessage object.
 */
class MessageParser
{
    public function parse(array $rawMessage, int $storeId = 0): UnifiedMessageInterface
    {
        $msg = new UnifiedMessage();

        $senderEmail = $this->extractEmail($rawMessage['from'] ?? '');
        $messageId   = $rawMessage['message_id'] ?? uniqid('email_', true);
        $timestamp   = $rawMessage['date'] ?? date('c');

        $msg->setChannelType('email')
            ->setMessageId($messageId)
            ->setSessionId($senderEmail)           // email address = natural session ID
            ->setCustomerIdentifier($senderEmail)
            ->setCustomerVerified(false)           // set to true after Magento lookup
            ->setContentText($rawMessage['body'] ?? '')
            ->setAttachments($rawMessage['attachments'] ?? [])
            ->setReplyTo([
                'email'    => $senderEmail,
                'threadId' => $rawMessage['thread_id'] ?? $messageId,
                'subject'  => $rawMessage['subject'] ?? '',
                'replyHeader' => 'In-Reply-To: ' . $messageId,
            ])
            ->setTimestamp($this->normaliseDate($timestamp))
            ->setStoreId($storeId)
            ->setAutoReply((bool)($rawMessage['is_auto_reply'] ?? false));

        return $msg;
    }

    private function extractEmail(string $from): string
    {
        // Handle "Name <email@example.com>" format
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($from));
    }

    private function normaliseDate(string $date): string
    {
        try {
            $dt = new \DateTime($date);
            return $dt->format('c');
        } catch (\Exception) {
            return date('c');
        }
    }
}
