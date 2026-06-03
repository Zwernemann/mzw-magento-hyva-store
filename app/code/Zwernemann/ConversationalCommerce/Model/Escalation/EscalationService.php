<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Escalation;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\Data\ConversationInterface;
use Zwernemann\ConversationalCommerce\Model\Conversation;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\Conversation as ConversationResource;
use Zwernemann\ConversationalCommerce\Model\Channel\Email\MailSender;

/**
 * Transitions a conversation to STATUS_ESCALATED and notifies the configured admin.
 */
class EscalationService
{
    private const XML_ADMIN_EMAIL = 'conversional_commerce/escalation/admin_email';

    public function __construct(
        private readonly ConversationResource $conversationResource,
        private readonly MailSender           $mailSender,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface      $logger
    ) {}

    /**
     * Marks the conversation as escalated, persists it, and sends an admin notification.
     */
    public function escalate(Conversation $conversation, string $reason, int $storeId = 0): void
    {
        $conversation
            ->setStatus(ConversationInterface::STATUS_ESCALATED)
            ->setEscalationReason($reason)
            ->setData('escalated_at', date('Y-m-d H:i:s'))
            ->setDataChanges(true);
        $this->conversationResource->save($conversation);

        [$scope, $code] = $storeId > 0
            ? [ScopeInterface::SCOPE_STORE, $storeId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];

        $adminEmail = trim((string)($this->scopeConfig->getValue(self::XML_ADMIN_EMAIL, $scope, $code) ?? ''));
        if ($adminEmail !== '') {
            $this->sendNotification($conversation, $reason, $adminEmail, $storeId);
        }
    }

    /**
     * Releases an escalated conversation back to STATUS_OPEN.
     */
    public function approve(Conversation $conversation, string $approvedByEmail): void
    {
        $conversation
            ->setStatus(ConversationInterface::STATUS_OPEN)
            ->setEscalationApprovedAt(date('Y-m-d H:i:s'))
            ->setEscalationApprovedBy($approvedByEmail)
            ->setDataChanges(true);
        $this->conversationResource->save($conversation);
    }

    private function sendNotification(
        Conversation $conversation,
        string $reason,
        string $adminEmail,
        int $storeId
    ): void {
        $convId  = (int)$conversation->getId();
        $email   = htmlspecialchars($conversation->getCustomerEmail());
        $channel = htmlspecialchars($conversation->getChannelType());
        $subject = sprintf('[ConversationalCommerce] Konversation #%d eskaliert – Freigabe erforderlich', $convId);

        $html = '<p style="font-family:sans-serif">Eine KI-Konversation wurde eskaliert und wartet auf manuelle Freigabe.</p>'
            . '<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">'
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Konversation&nbsp;#</strong></td><td>%d</td></tr>', $convId)
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Kunde</strong></td><td>%s</td></tr>', $email)
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Kanal</strong></td><td>%s</td></tr>', $channel)
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Eskalationsgrund</strong></td><td>%s</td></tr>', htmlspecialchars($reason))
            . '</table>'
            . '<p style="font-family:sans-serif;margin-top:16px">'
            . 'Die KI hat die Antwort <strong>zurückgehalten</strong>. '
            . 'Bitte öffnen Sie das Admin-Backend, prüfen Sie die Konversation und klicken Sie auf <em>Freigeben</em>.'
            . '</p>';

        $text = sprintf(
            "Konversation #%d eskaliert\nKunde: %s\nKanal: %s\nGrund: %s\n\n"
            . "Bitte im Admin-Backend prüfen und freigeben (Konversationsliste → Freigeben).",
            $convId,
            $conversation->getCustomerEmail(),
            $conversation->getChannelType(),
            $reason
        );

        try {
            $this->mailSender->send($adminEmail, $subject, $html, $text, '', '', [], $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EscalationService: Admin-Benachrichtigung fehlgeschlagen – ' . $e->getMessage(),
                ['conversation_id' => $convId]
            );
        }
    }
}
