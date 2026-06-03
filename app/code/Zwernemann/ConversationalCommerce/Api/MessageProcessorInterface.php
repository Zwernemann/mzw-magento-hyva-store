<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Api;

use Zwernemann\ConversationalCommerce\Api\Data\UnifiedMessageInterface;

interface MessageProcessorInterface
{
    /**
     * Process an incoming unified message and trigger the appropriate action.
     *
     * @return array{text: string, html: string}
     */
    public function process(UnifiedMessageInterface $message): array;
}
