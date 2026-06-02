<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Block\Adminhtml\Aliases;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\CustomerAliasEmail;

class Index extends Template
{
    protected $_template = 'Zwernemann_ConversationalCommerce::aliases/index.phtml';

    public function __construct(
        Context $context,
        private readonly CustomerAliasEmail $aliasResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<int, array<string, mixed>> */
    public function getAliases(): array
    {
        return $this->aliasResource->getAll();
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('conversationalcommerce/aliases/save');
    }

    public function getDeleteUrl(int $id): string
    {
        return $this->getUrl('conversationalcommerce/aliases/delete', ['id' => $id]);
    }
}
