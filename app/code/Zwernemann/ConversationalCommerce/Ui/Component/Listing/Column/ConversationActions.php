<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ConversationActions extends Column
{
    public function __construct(
        ContextInterface  $context,
        UiComponentFactory $factory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $factory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $actions = [
                'view' => [
                    'href'  => $this->urlBuilder->getUrl('conversationalcommerce/index/view', ['id' => $item['id']]),
                    'label' => __('View'),
                ],
            ];

            if (($item['status_code'] ?? $item['status'] ?? '') === 'escalated') {
                $actions['approve'] = [
                    'href'    => $this->urlBuilder->getUrl(
                        'conversationalcommerce/escalation/approve',
                        ['id' => $item['id']]
                    ),
                    'label'   => __('Freigeben'),
                    'confirm' => [
                        'title'   => __('Konversation freigeben'),
                        'message' => __('KI-Pause aufheben und Konversation fortsetzen?'),
                    ],
                ];
            }

            $item[$this->getData('name')] = $actions;
        }
        return $dataSource;
    }
}
