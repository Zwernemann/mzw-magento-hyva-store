<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Channel\Email;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\ResourceModel\ConversationMessage as MessageResource;

/**
 * Polls an IMAP mailbox and returns messages not yet in the DB.
 * Uses DB message_id deduplication so seen/unseen flags don't matter.
 * No ext-imap or third-party library required.
 */
class MailPoller
{
    private const XML_PATH_HOST    = 'conversional_commerce/imap/host';
    private const XML_PATH_PORT    = 'conversional_commerce/imap/port';
    private const XML_PATH_SSL     = 'conversional_commerce/imap/use_ssl';
    private const XML_PATH_USER    = 'conversional_commerce/imap/username';
    private const XML_PATH_PASS    = 'conversional_commerce/imap/password';
    private const XML_PATH_MAILBOX = 'conversional_commerce/imap/mailbox';

    private const FETCH_DAYS = 30;

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly LoggerInterface      $logger,
        private readonly EncryptorInterface   $encryptor,
        private readonly MessageResource      $messageResource
    ) {}

    /**
     * @param  int $storeId  0 = read from global default scope
     * @return array<int, array<string, mixed>>
     */
    public function fetchUnseen(int $storeId = 0): array
    {
        [$scope, $code] = $storeId > 0
            ? [ScopeInterface::SCOPE_STORE, $storeId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];

        $host       = $this->config->getValue(self::XML_PATH_HOST, $scope, $code) ?? '';
        $port       = (int)($this->config->getValue(self::XML_PATH_PORT, $scope, $code) ?? 993);
        $useSsl     = (bool)$this->config->getValue(self::XML_PATH_SSL, $scope, $code);
        $user       = $this->config->getValue(self::XML_PATH_USER, $scope, $code) ?? '';
        $pass       = trim($this->encryptor->decrypt(
            $this->config->getValue(self::XML_PATH_PASS, $scope, $code) ?? ''
        ));
        $mailbox    = $this->config->getValue(self::XML_PATH_MAILBOX, $scope, $code) ?? 'INBOX';
        $encryption = $this->resolveEncryption($port, $useSsl);

        if (empty($host) || empty($user)) {
            $this->logger->warning(sprintf(
                'ConversationalCommerce: IMAP not configured (store_id: %d).', $storeId
            ));
            return [];
        }

        $this->logger->info(sprintf(
            'ConversationalCommerce: Connecting to IMAP %s:%d (encryption: %s, mailbox: %s, store_id: %d)',
            $host, $port, $encryption ?: 'none', $mailbox, $storeId
        ));

        $client = new NativeImapClient();
        try {
            $client->connect($host, $port, $encryption);
            $client->login($user, $pass);
            $client->select($mailbox);

            $since = new \DateTimeImmutable('-' . self::FETCH_DAYS . ' days');
            $uids  = $client->searchSince($since);

            $this->logger->info(sprintf(
                'ConversationalCommerce: IMAP %s/%s – %d message(s) in last %d days',
                $host, $mailbox, count($uids), self::FETCH_DAYS
            ));

            $result  = [];
            $skipped = 0;
            foreach ($uids as $uid) {
                try {
                    $parsed    = $client->fetchMessage($uid);
                    $messageId = $parsed['message_id'];

                    if ($this->messageResource->messageIdExists($messageId)) {
                        $skipped++;
                        continue;
                    }

                    $this->logger->info(sprintf(
                        'ConversationalCommerce: New message uid=%d from: %s, subject: %s',
                        $uid, $parsed['from'], $parsed['subject']
                    ));
                    $result[] = $parsed;

                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'ConversationalCommerce: Failed to fetch/parse UID %d – %s', $uid, $e->getMessage()
                    ));
                }
            }

            $this->logger->info(sprintf(
                'ConversationalCommerce: IMAP poll done – %d new, %d already processed',
                count($result), $skipped
            ));

            $client->logout();
            return $result;

        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'ConversationalCommerce: IMAP connection failed – %s (host: %s:%d)',
                $e->getMessage(), $host, $port
            ));
            $client->logout();
            return [];
        }
    }

    private function resolveEncryption(int $port, bool $useSsl): string|false
    {
        return match (true) {
            $port === 143             => 'starttls',
            $port === 993 || $useSsl => 'ssl',
            default                  => false,
        };
    }
}
