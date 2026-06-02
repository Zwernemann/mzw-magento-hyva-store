<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Api\Data;

interface InboundResponseInterface
{
    /**
     * @return bool
     */
    public function getSuccess(): bool;

    /**
     * @param bool $success
     * @return $this
     */
    public function setSuccess(bool $success): self;

    /**
     * @return string
     */
    public function getErrorMessage(): string;

    /**
     * @param string $message
     * @return $this
     */
    public function setErrorMessage(string $message): self;

    /**
     * @return string
     */
    public function getResponseText(): string;

    /**
     * @param string $text
     * @return $this
     */
    public function setResponseText(string $text): self;

    /**
     * @return string
     */
    public function getResponseHtml(): string;

    /**
     * @param string $html
     * @return $this
     */
    public function setResponseHtml(string $html): self;

    /**
     * @return string
     */
    public function getIntent(): string;

    /**
     * @param string $intent
     * @return $this
     */
    public function setIntent(string $intent): self;

    /**
     * @return string
     */
    public function getActionType(): string;

    /**
     * @param string $actionType
     * @return $this
     */
    public function setActionType(string $actionType): self;

    /**
     * @return string
     */
    public function getMessageType(): string;

    /**
     * @param string $type
     * @return $this
     */
    public function setMessageType(string $type): self;

    /**
     * @return string
     */
    public function getProductsJson(): string;

    /**
     * @param string $json
     * @return $this
     */
    public function setProductsJson(string $json): self;
}
