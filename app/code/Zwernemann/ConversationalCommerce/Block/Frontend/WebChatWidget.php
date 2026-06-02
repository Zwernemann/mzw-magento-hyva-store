<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Block\Frontend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

class WebChatWidget extends Template
{
    protected $_template = 'Zwernemann_ConversationalCommerce::webchat/widget.phtml';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'conversional_commerce/webchat/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getTitle(): string
    {
        return (string)($this->scopeConfig->getValue(
            'conversional_commerce/webchat/title',
            ScopeInterface::SCOPE_STORE
        ) ?: 'Chat with us');
    }

    public function getButtonLabel(): string
    {
        return (string)($this->scopeConfig->getValue(
            'conversional_commerce/webchat/button_label',
            ScopeInterface::SCOPE_STORE
        ) ?: 'Chat');
    }

    public function getWidgetConfigJson(): string
    {
        return (string)json_encode([
            'title'          => $this->getTitle(),
            'welcomeMessage' => (string)($this->scopeConfig->getValue(
                'conversional_commerce/webchat/welcome_message',
                ScopeInterface::SCOPE_STORE
            ) ?: 'Hello! How can I help you today?'),
            'primaryColor'   => (string)($this->scopeConfig->getValue(
                'conversional_commerce/webchat/primary_color',
                ScopeInterface::SCOPE_STORE
            ) ?: '#1a73e8'),
            'buttonLabel'    => $this->getButtonLabel(),
            'sendUrl'        => $this->getUrl('cc_webchat/message/send'),
            'storeId'        => (int)$this->_storeManager->getStore()->getId(),
        ]);
    }
}
