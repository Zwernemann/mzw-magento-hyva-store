<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Channel\Email;

use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\ChannelInterface;
use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;

class EmailChannel implements ChannelInterface
{
    public function __construct(
        private readonly MailPoller     $poller,
        private readonly MailSender     $sender,
        private readonly MessageParser  $parser,
        private readonly LoggerInterface $logger
    ) {}

    public function getChannelType(): string
    {
        return 'email';
    }

    public function pollMessages(int $storeId = 0): array
    {
        $raw = $this->poller->fetchUnseen($storeId);

        if (empty($raw)) {
            return [];
        }

        $messages = [];
        foreach ($raw as $rawMsg) {
            try {
                $parsed     = $this->parser->parse($rawMsg, $storeId);
                $messages[] = $parsed;
                $this->logger->info(sprintf(
                    'ConversationalCommerce: Email parsed – channel: %s, from: %s, session: %s',
                    $parsed->getChannelType(),
                    $parsed->getCustomerIdentifier(),
                    $parsed->getSessionId()
                ));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'ConversationalCommerce: Failed to parse email (id: %s) – %s',
                    $rawMsg['message_id'] ?? 'unknown',
                    $e->getMessage()
                ));
            }
        }
        return $messages;
    }

    public function sendResponse(
        UnifiedMessageInterface $originalMessage,
        string $responseText,
        string $responseHtml,
        array  $metadata = []
    ): void {
        $replyTo   = $originalMessage->getReplyTo();
        $toEmail   = $replyTo['email'] ?? $originalMessage->getCustomerIdentifier();
        $inReplyTo = $replyTo['threadId'] ?? '';
        $origSubj  = $replyTo['subject'] ?? '';
        $subject   = str_starts_with(strtolower($origSubj), 're:')
            ? $origSubj
            : 'Re: ' . $origSubj;

        $inlineImages = $metadata['inline_images'] ?? [];

        $success = $this->sender->send(
            toEmail:      $toEmail,
            subject:      $subject,
            htmlBody:     $responseHtml,
            textBody:     $responseText,
            inReplyTo:    $inReplyTo,
            references:   $inReplyTo,
            inlineImages: $inlineImages,
            storeId:      $originalMessage->getStoreId()
        );

        if (!$success) {
            $this->logger->error(
                'ConversationalCommerce: Failed to send email response to ' . $toEmail
            );
        }
    }
}
