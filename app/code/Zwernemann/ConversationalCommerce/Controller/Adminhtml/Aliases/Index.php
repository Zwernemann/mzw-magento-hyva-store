<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Controller\Adminhtml\Aliases;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_ConversationalCommerce::aliases';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\View\Result\Page
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Conversational Commerce – Customer Email Aliases'));
        return $page;
    }
}
