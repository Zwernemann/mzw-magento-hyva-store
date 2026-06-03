<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Controller\Adminhtml\Privacy;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Zwernemann\ConversationalCommerce\Api\GdprServiceInterface;

/**
 * Admin action: anonymize all conversations for a given customer email (GDPR Art. 17).
 *
 * POST  conversationalcommerce/privacy/anonymizecustomer
 * Params: email (required)  OR  conversation_id (required)
 */
class AnonymizeCustomer extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_ConversationalCommerce::privacy';

    public function __construct(
        Context $context,
        private readonly GdprServiceInterface $gdprService,
        private readonly JsonFactory          $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->getRequest()->isPost()) {
            return $result->setData(['success' => false, 'message' => 'POST required']);
        }

        $email          = trim((string)$this->getRequest()->getParam('email', ''));
        $conversationId = (int)$this->getRequest()->getParam('conversation_id', 0);

        try {
            if ($conversationId > 0) {
                $ok = $this->gdprService->anonymizeByConversationId($conversationId);
                if ($ok) {
                    return $result->setData([
                        'success' => true,
                        'message' => (string)__('Gespräch #%1 wurde anonymisiert.', $conversationId),
                    ]);
                }
                return $result->setData([
                    'success' => false,
                    'message' => (string)__('Gespräch #%1 nicht gefunden.', $conversationId),
                ]);
            }

            if ($email === '') {
                return $result->setData(['success' => false, 'message' => (string)__('E-Mail-Adresse ist erforderlich.')]);
            }

            $count = $this->gdprService->anonymizeByEmail($email);
            return $result->setData([
                'success' => true,
                'message' => (string)__('%1 Gespräch(e) für %2 wurden anonymisiert.', $count, $email),
                'count'   => $count,
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => (string)__('Fehler: %1', $e->getMessage()),
            ]);
        }
    }
}
