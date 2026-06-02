<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Api\Data;

interface InboundRequestInterface
{
    public function getChannelType(): string;
    public function setChannelType(string $channelType): self;

    /** External message ID used for deduplication */
    public function getMessageId(): string;
    public function setMessageId(string $messageId): self;

    /** Customer identifier: phone number for WhatsApp, email for email channel */
    public function getCustomerIdentifier(): string;
    public function setCustomerIdentifier(string $identifier): self;

    /** Conversation grouping key (usually equals customerIdentifier) */
    public function getSessionId(): string;
    public function setSessionId(string $sessionId): self;

    public function getContentText(): string;
    public function setContentText(string $text): self;

    public function getTimestamp(): string;
    public function setTimestamp(string $timestamp): self;

    /** Shared secret to authenticate connector requests */
    public function getConnectorSecret(): string;
    public function setConnectorSecret(string $secret): self;
}
