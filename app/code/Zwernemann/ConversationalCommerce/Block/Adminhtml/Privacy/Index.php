<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Block\Adminhtml\Privacy;

use Magento\Backend\Block\Template;

class Index extends Template
{
    protected $_template = 'Zwernemann_ConversationalCommerce::privacy/index.phtml';

    public function getAnonymizeUrl(): string
    {
        return $this->getUrl('conversationalcommerce/privacy/anonymizecustomer');
    }
}
