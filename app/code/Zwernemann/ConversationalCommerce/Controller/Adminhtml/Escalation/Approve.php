<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Controller\Adminhtml\Escalation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Zwernemann\ConversationalCommerce\Api\Data\ConversationInterface;
use Zwernemann\ConversationalCommerce\Api\Data\ConversationRepositoryInterface;
use Zwernemann\ConversationalCommerce\Model\Escalation\EscalationService;

/**
 * Releases an escalated conversation back to STATUS_OPEN.
 */
class Approve extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_ConversationalCommerce::conversations';

    public function __construct(
        Context $context,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly EscalationService               $escalationService,
        private readonly AuthSession                     $authSession
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id     = (int)$this->getRequest()->getParam('id');
        $result = $this->resultRedirectFactory->create();

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Ungültige Konversations-ID.'));
            return $result->setPath('conversationalcommerce/index/index');
        }

        try {
            $conversation = $this->conversationRepository->getById($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Konversation nicht gefunden.'));
            return $result->setPath('conversationalcommerce/index/index');
        }

        if ($conversation->getStatus() !== ConversationInterface::STATUS_ESCALATED) {
            $this->messageManager->addWarningMessage(
                __('Konversation #%1 ist nicht eskaliert (Status: %2).', $id, $conversation->getStatus())
            );
            return $result->setPath('conversationalcommerce/index/index');
        }

        $user       = $this->authSession->getUser();
        $adminEmail = $user ? (string)$user->getEmail() : 'admin';

        $this->escalationService->approve($conversation, $adminEmail);

        $this->messageManager->addSuccessMessage(
            __('Konversation #%1 wurde freigegeben. Die KI kann jetzt wieder antworten.', $id)
        );
        return $result->setPath('conversationalcommerce/index/index');
    }
}
