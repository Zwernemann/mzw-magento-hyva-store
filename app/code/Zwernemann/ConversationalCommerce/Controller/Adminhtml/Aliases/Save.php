<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Controller\Adminhtml\Aliases;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\CustomerAliasEmail;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_ConversationalCommerce::aliases';

    public function __construct(
        Context $context,
        private readonly CustomerAliasEmail         $aliasResource,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('conversationalcommerce/aliases/index');

        $customerEmail = trim((string)$this->getRequest()->getParam('customer_email'));
        $aliasEmail    = trim((string)$this->getRequest()->getParam('email'));
        $label         = trim((string)$this->getRequest()->getParam('label'));

        if ($customerEmail === '' || $aliasEmail === '') {
            $this->messageManager->addErrorMessage(__('Customer email and alias email address are required.'));
            return $redirect;
        }

        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Please enter a valid customer email address.'));
            return $redirect;
        }

        if (!filter_var($aliasEmail, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Please enter a valid alias email address.'));
            return $redirect;
        }

        try {
            $customer   = $this->customerRepository->get($customerEmail);
            $customerId = (int)$customer->getId();
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                __('No customer found with email address "%1".', $customerEmail)
            );
            return $redirect;
        }

        try {
            $this->aliasResource->add($customerId, $aliasEmail, $label);
            $this->messageManager->addSuccessMessage(
                __('Alias %1 has been saved for %2 (Customer #%3).', $aliasEmail, $customerEmail, $customerId)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save alias: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
