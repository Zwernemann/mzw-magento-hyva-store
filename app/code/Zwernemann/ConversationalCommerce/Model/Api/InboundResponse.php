<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Api;

use Zwernemann\ConversationalCommerce\Api\Data\InboundResponseInterface;

class InboundResponse implements InboundResponseInterface
{
    private bool   $success       = true;
    private string $errorMessage  = '';
    private string $responseText  = '';
    private string $responseHtml  = '';
    private string $intent        = '';
    private string $actionType    = '';
    private string $messageType   = 'text';
    private string $productsJson  = '[]';

    public function getSuccess(): bool        { return $this->success; }
    public function setSuccess(bool $v): self { $this->success = $v; return $this; }

    public function getErrorMessage(): string        { return $this->errorMessage; }
    public function setErrorMessage(string $v): self { $this->errorMessage = $v; return $this; }

    public function getResponseText(): string        { return $this->responseText; }
    public function setResponseText(string $v): self { $this->responseText = $v; return $this; }

    public function getResponseHtml(): string        { return $this->responseHtml; }
    public function setResponseHtml(string $v): self { $this->responseHtml = $v; return $this; }

    public function getIntent(): string        { return $this->intent; }
    public function setIntent(string $v): self { $this->intent = $v; return $this; }

    public function getActionType(): string        { return $this->actionType; }
    public function setActionType(string $v): self { $this->actionType = $v; return $this; }

    public function getMessageType(): string        { return $this->messageType; }
    public function setMessageType(string $v): self { $this->messageType = $v; return $this; }

    public function getProductsJson(): string        { return $this->productsJson; }
    public function setProductsJson(string $v): self { $this->productsJson = $v; return $this; }
}
