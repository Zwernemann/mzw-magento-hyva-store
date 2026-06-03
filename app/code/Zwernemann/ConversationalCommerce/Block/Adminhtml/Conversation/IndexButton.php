<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Block\Adminhtml\Conversation;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class IndexButton implements ButtonProviderInterface
{
    public function __construct(private readonly Context $context) {}

    public function getButtonData(): array
    {
        return [
            'label'      => __('Re-Index Products'),
            'class'      => 'action-secondary',
            'on_click'   => sprintf("location.href = '%s';", $this->context->getUrlBuilder()->getUrl(
                'conversationalcommerce/index/indexproducts'
            )),
            'sort_order' => 10,
        ];
    }
}
