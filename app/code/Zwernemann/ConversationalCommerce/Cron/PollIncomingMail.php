<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Api\MessageProcessorInterface;
use Zwernemann\ConversationalCommerce\Model\Channel\Email\EmailChannel;

/**
 * Cron job: Poll IMAP mailboxes for new messages and process them.
 * Iterates all active stores; only polls stores with imap/enabled = 1.
 * Runs every 2 minutes by default (configurable in crontab.xml).
 */
class PollIncomingMail
{
    private const XML_PATH_IMAP_ENABLED    = 'conversional_commerce/imap/enabled';
    private const XML_PATH_GENERAL_ENABLED = 'conversional_commerce/general/enabled';

    public function __construct(
        private readonly EmailChannel              $emailChannel,
        private readonly MessageProcessorInterface $processor,
        private readonly ScopeConfigInterface      $config,
        private readonly StoreManagerInterface     $storeManager,
        private readonly LoggerInterface           $logger
    ) {}

    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->pollStore($store);
        }
    }

    private function pollStore(StoreInterface $store): void
    {
        $storeId = (int)$store->getId();

        if (!$this->config->isSetFlag(self::XML_PATH_GENERAL_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return;
        }

        if (!$this->config->isSetFlag(self::XML_PATH_IMAP_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return;
        }

        $messages = $this->emailChannel->pollMessages($storeId);

        if (empty($messages)) {
            return;
        }

        $this->logger->info(sprintf(
            'ConversationalCommerce: Processing %d new message(s) for store %s (id: %d)',
            count($messages), $store->getCode(), $storeId
        ));

        foreach ($messages as $message) {
            $this->logger->info(sprintf(
                'ConversationalCommerce: Processing message %s from %s (store_id: %d)',
                $message->getMessageId(),
                $message->getCustomerIdentifier(),
                $storeId
            ));
            try {
                $response = $this->processor->process($message);
                $this->logger->info(sprintf(
                    'ConversationalCommerce: Message %s processed – response: %s',
                    $message->getMessageId(),
                    mb_substr($response['text'] ?? '', 0, 120)
                ));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'ConversationalCommerce: Failed to process message %s – %s | %s',
                    $message->getMessageId(),
                    $e->getMessage(),
                    $e->getFile() . ':' . $e->getLine()
                ));
            }
        }
    }
}
