<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Api;

use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;

interface ChannelInterface
{
    public function getChannelType(): string;

    /**
     * Poll for new inbound messages and return them as UnifiedMessage objects.
     *
     * @return UnifiedMessageInterface[]
     */
    public function pollMessages(): array;

    /**
     * Send a response back through this channel.
     *
     * @param UnifiedMessageInterface $originalMessage The message being replied to
     * @param string $responseText Plain-text response content
     * @param string $responseHtml HTML response content (may contain inline images)
     * @param array<string, mixed> $metadata Channel-specific metadata (thread ID, etc.)
     */
    public function sendResponse(
        UnifiedMessageInterface $originalMessage,
        string $responseText,
        string $responseHtml,
        array $metadata = []
    ): void;
}
