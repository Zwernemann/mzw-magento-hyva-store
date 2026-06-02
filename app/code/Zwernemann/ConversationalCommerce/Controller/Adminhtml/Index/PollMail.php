<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Zwernemann\ConversationalCommerce\Api\MessageProcessorInterface;
use Zwernemann\ConversationalCommerce\Model\Channel\Email\EmailChannel;

class PollMail extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_ConversationalCommerce::conversations';

    public function __construct(
        Context $context,
        private readonly EmailChannel              $emailChannel,
        private readonly MessageProcessorInterface $processor
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Redirect
    {
        $messages = $this->emailChannel->pollMessages();

        if (empty($messages)) {
            $this->messageManager->addNoticeMessage(__('No new messages found.'));
        } else {
            foreach ($messages as $message) {
                try {
                    $this->processor->process($message);
                } catch (\Throwable $e) {
                    $this->messageManager->addErrorMessage(
                        __('Error processing message %1: %2', $message->getMessageId(), $e->getMessage())
                    );
                }
            }
            $this->messageManager->addSuccessMessage(
                __('Processed %1 message(s).', count($messages))
            );
        }

        return $this->resultRedirectFactory->create()->setPath('conversationalcommerce/index/index');
    }
}
