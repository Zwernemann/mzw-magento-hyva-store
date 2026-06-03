<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Controller\Adminhtml\Aliases;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\CustomerAliasEmail;

class Delete extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_ConversationalCommerce::aliases';

    public function __construct(
        Context $context,
        private readonly CustomerAliasEmail $aliasResource
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('conversationalcommerce/aliases/index');

        $id = (int)$this->getRequest()->getParam('id');
        if ($id <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid alias ID.'));
            return $redirect;
        }

        try {
            $this->aliasResource->delete($id);
            $this->messageManager->addSuccessMessage(__('Alias has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete alias: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
