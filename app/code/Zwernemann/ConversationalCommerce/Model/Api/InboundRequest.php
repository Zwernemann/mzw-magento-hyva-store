<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Api;

use Zwernemann\ConversationalCommerce\Api\Data\InboundRequestInterface;

class InboundRequest implements InboundRequestInterface
{
    private string $channelType        = 'whatsapp';
    private string $messageId          = '';
    private string $customerIdentifier = '';
    private string $sessionId          = '';
    private string $contentText        = '';
    private string $timestamp          = '';
    private string $connectorSecret    = '';

    public function getChannelType(): string        { return $this->channelType; }
    public function setChannelType(string $v): self { $this->channelType = $v; return $this; }

    public function getMessageId(): string        { return $this->messageId; }
    public function setMessageId(string $v): self { $this->messageId = $v; return $this; }

    public function getCustomerIdentifier(): string        { return $this->customerIdentifier; }
    public function setCustomerIdentifier(string $v): self { $this->customerIdentifier = $v; return $this; }

    public function getSessionId(): string        { return $this->sessionId; }
    public function setSessionId(string $v): self { $this->sessionId = $v; return $this; }

    public function getContentText(): string        { return $this->contentText; }
    public function setContentText(string $v): self { $this->contentText = $v; return $this; }

    public function getTimestamp(): string        { return $this->timestamp; }
    public function setTimestamp(string $v): self { $this->timestamp = $v; return $this; }

    public function getConnectorSecret(): string        { return $this->connectorSecret; }
    public function setConnectorSecret(string $v): self { $this->connectorSecret = $v; return $this; }
}
