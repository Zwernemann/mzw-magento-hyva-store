<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Api;

use Zwernemann\ConversationalCommerce\Api\Data\InboundResponseInterface;

interface InboundApiInterface
{
    /**
     * Process an inbound message from an external channel connector (e.g. WhatsApp).
     *
     * @param string $channelType
     * @param string $messageId
     * @param string $customerIdentifier
     * @param string $sessionId
     * @param string $contentText
     * @param string $connectorSecret
     * @param string $timestamp
     * @return \Zwernemann\ConversationalCommerce\Api\Data\InboundResponseInterface
     */
    public function processInbound(
        string $channelType,
        string $messageId,
        string $customerIdentifier,
        string $sessionId,
        string $contentText,
        string $connectorSecret,
        string $timestamp = ''
    ): InboundResponseInterface;
}
