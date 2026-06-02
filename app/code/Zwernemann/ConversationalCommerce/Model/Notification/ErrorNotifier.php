<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Notification;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\Channel\Email\MailSender;

/**
 * Sends a one-time-per-hour admin notification on critical system errors
 * (LLM API failures, pipeline crashes, etc.).
 *
 * Configure the recipient under:
 *   Stores → Configuration → Zwernemann → Conversational Commerce
 *     → Systemfehler-Benachrichtigungen → E-Mail für kritische Fehler
 */
class ErrorNotifier
{
    private const XML_ERROR_EMAIL = 'conversional_commerce/notifications/error_email';
    private const CACHE_PREFIX    = 'cc_errnotify_';
    private const THROTTLE_TTL    = 3600; // seconds — one notification per error type per hour

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly MailSender           $mailSender,
        private readonly CacheInterface       $cache,
        private readonly LoggerInterface      $logger,
    ) {}

    /**
     * Send an admin notification for a critical error.
     *
     * Identical error types are throttled to at most one email per hour.
     * If no error_email is configured, this is a no-op.
     */
    public function notify(string $errorType, string $message, int $storeId = 0): void
    {
        [$scope, $code] = $storeId > 0
            ? [ScopeInterface::SCOPE_STORE, $storeId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];

        $email = trim((string)($this->scopeConfig->getValue(self::XML_ERROR_EMAIL, $scope, $code) ?? ''));
        if ($email === '') {
            return;
        }

        // Throttle: same error type at most once per TTL window
        $cacheKey = self::CACHE_PREFIX . md5($errorType);
        if ($this->cache->load($cacheKey)) {
            return;
        }
        $this->cache->save('1', $cacheKey, [], self::THROTTLE_TTL);

        $time    = date('Y-m-d H:i:s T');
        $subject = '[ConversationalCommerce] Systemfehler: ' . $errorType;
        $msgHtml = nl2br(htmlspecialchars($message));

        $html = '<p style="font-family:sans-serif;color:#c00"><strong>ConversationalCommerce — Kritischer Systemfehler</strong></p>'
            . '<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">'
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Fehlertyp</strong></td><td>%s</td></tr>', htmlspecialchars($errorType))
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Meldung</strong></td><td>%s</td></tr>', $msgHtml)
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Zeit</strong></td><td>%s</td></tr>', $time)
            . sprintf('<tr><td style="padding:4px 12px 4px 0"><strong>Store ID</strong></td><td>%d</td></tr>', $storeId)
            . '</table>'
            . '<p style="font-family:sans-serif;color:#555;margin-top:16px">'
            . 'Das System kann Nachrichten möglicherweise nicht verarbeiten.<br>'
            . 'Bitte Logdatei prüfen: <code>var/log/conversationalcommerce.log</code></p>'
            . '<p style="font-family:sans-serif;color:#999;font-size:12px">'
            . 'Identische Fehler werden maximal 1× pro Stunde gemeldet.</p>';

        $text = sprintf(
            "ConversationalCommerce — Systemfehler\n\nTyp: %s\nMeldung: %s\nZeit: %s\nStore ID: %d\n\n"
                . "Logdatei: var/log/conversationalcommerce.log\n"
                . "(Identische Fehler werden max. 1x pro Stunde gemeldet.)",
            $errorType,
            $message,
            $time,
            $storeId
        );

        try {
            $this->mailSender->send($email, $subject, $html, $text, '', '', [], $storeId);
            $this->logger->info('[ErrorNotifier] Fehlerbenachrichtigung gesendet', [
                'to'   => $email,
                'type' => $errorType,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[ErrorNotifier] Senden fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
