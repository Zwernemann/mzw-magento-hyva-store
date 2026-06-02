<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Channel\WebChat;

use Zwernemann\ConversationalCommerce\Api\ChannelInterface;
use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;

class WebChatChannel implements ChannelInterface
{
    public function getChannelType(): string
    {
        return 'webchat';
    }

    public function pollMessages(): array
    {
        return [];
    }

    public function sendResponse(
        UnifiedMessageInterface $originalMessage,
        string $responseText,
        string $responseHtml,
        array $metadata = []
    ): void {
        // No-op: WebChat responses are returned synchronously via the HTTP response in Send.php
    }
}
