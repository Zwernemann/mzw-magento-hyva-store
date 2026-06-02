<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Channel\Email;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends HTML emails via a raw SMTP socket connection.
 * Product images are embedded as CID inline attachments.
 */
class MailSender
{
    private const XML_PATH_HOST      = 'conversional_commerce/smtp/host';
    private const XML_PATH_PORT      = 'conversional_commerce/smtp/port';
    private const XML_PATH_TLS       = 'conversional_commerce/smtp/use_tls';
    private const XML_PATH_USER      = 'conversional_commerce/smtp/username';
    private const XML_PATH_PASS      = 'conversional_commerce/smtp/password';
    private const XML_PATH_FROM_MAIL = 'conversional_commerce/smtp/from_email';
    private const XML_PATH_FROM_NAME = 'conversional_commerce/smtp/from_name';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly LoggerInterface      $logger,
        private readonly EncryptorInterface   $encryptor
    ) {}

    /**
     * @param array<string, array{cid: string, data: string, mime: string}> $inlineImages
     */
    public function send(
        string $toEmail,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $inReplyTo    = '',
        string $references   = '',
        array  $inlineImages = [],
        int    $storeId      = 0
    ): bool {
        [$scope, $code] = $storeId > 0
            ? [ScopeInterface::SCOPE_STORE, $storeId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];

        $host     = $this->config->getValue(self::XML_PATH_HOST, $scope, $code) ?? '';
        $port     = (int)($this->config->getValue(self::XML_PATH_PORT, $scope, $code) ?? 587);
        $useTls   = (bool)$this->config->getValue(self::XML_PATH_TLS, $scope, $code);
        $user     = $this->config->getValue(self::XML_PATH_USER, $scope, $code) ?? '';
        $pass     = trim($this->encryptor->decrypt($this->config->getValue(self::XML_PATH_PASS, $scope, $code) ?? ''));
        $fromMail = $this->config->getValue(self::XML_PATH_FROM_MAIL, $scope, $code) ?? '';
        $fromName = $this->config->getValue(self::XML_PATH_FROM_NAME, $scope, $code) ?? 'ConversationalCommerce';

        if (empty($host) || empty($fromMail)) {
            $this->logger->warning(sprintf(
                'ConversationalCommerce: SMTP not configured (store_id: %d).', $storeId
            ));
            return false;
        }

        $boundary    = '=_' . bin2hex(random_bytes(12));
        $cidBoundary = '=_cid_' . bin2hex(random_bytes(12));
        $hasImages   = !empty($inlineImages);

        $headers = $this->buildHeaders(
            $fromMail, $fromName, $toEmail, $subject,
            $inReplyTo, $references, $boundary, $hasImages, $cidBoundary
        );
        $body = $this->buildBody(
            $textBody, $htmlBody, $boundary, $hasImages, $cidBoundary, $inlineImages
        );

        return $this->sendViaSMTP($host, $port, $useTls, $user, $pass, $fromMail, $toEmail, $headers, $body);
    }

    private function buildHeaders(
        string $fromMail, string $fromName, string $toEmail, string $subject,
        string $inReplyTo, string $references,
        string $boundary, bool $hasImages, string $cidBoundary
    ): string {
        $contentType = $hasImages
            ? "multipart/related; boundary=\"{$cidBoundary}\""
            : "multipart/alternative; boundary=\"{$boundary}\"";

        // Only base64-encode From name if it contains non-ASCII chars (avoids FROM_EXCESS_BASE64)
        $encodedName = mb_detect_encoding($fromName, 'ASCII', true)
            ? $fromName
            : '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $messageId = '<cc.' . bin2hex(random_bytes(12)) . '@' . gethostname() . '>';

        $h  = "From: {$encodedName} <{$fromMail}>\r\n";
        $h .= "To: {$toEmail}\r\n";
        $h .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $h .= "Date: " . date('r') . "\r\n";
        $h .= "Message-Id: {$messageId}\r\n";
        $h .= "MIME-Version: 1.0\r\n";
        $h .= "Content-Type: {$contentType}\r\n";
        $h .= "X-Mailer: Zwernemann ConversationalCommerce\r\n";

        if ($inReplyTo) {
            $h .= "In-Reply-To: <{$inReplyTo}>\r\n";
            $h .= "References: <{$references}>\r\n";
        }

        return $h;
    }

    /** @param array<string, array{cid: string, data: string, mime: string}> $inlineImages */
    private function buildBody(
        string $textBody, string $htmlBody,
        string $boundary, bool $hasImages,
        string $cidBoundary, array $inlineImages
    ): string {
        $altPart  = "--{$boundary}\r\n";
        $altPart .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $altPart .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $altPart .= chunk_split(base64_encode($textBody)) . "\r\n";
        $altPart .= "--{$boundary}\r\n";
        $altPart .= "Content-Type: text/html; charset=UTF-8\r\n";
        $altPart .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $altPart .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $altPart .= "--{$boundary}--\r\n";

        if (!$hasImages) {
            return $altPart;
        }

        $body  = "--{$cidBoundary}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        $body .= $altPart;

        foreach ($inlineImages as $cid => $img) {
            $body .= "--{$cidBoundary}\r\n";
            $body .= "Content-Type: {$img['mime']}\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-ID: <{$cid}>\r\n";
            $body .= "Content-Disposition: inline\r\n\r\n";
            $body .= chunk_split($img['data']) . "\r\n";
        }
        $body .= "--{$cidBoundary}--\r\n";

        return $body;
    }

    private function sendViaSMTP(
        string $host, int $port, bool $useTls,
        string $user, string $pass,
        string $from, string $to,
        string $headers, string $body
    ): bool {
        $this->logger->info(sprintf(
            'ConversationalCommerce: SMTP sending to %s via %s:%d (tls: %s)',
            $to, $host, $port, $useTls ? 'yes' : 'no'
        ));

        try {
            $context = stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

            if (!$sock) {
                $this->logger->error("ConversationalCommerce: SMTP connect failed: {$errstr} ({$errno})");
                return false;
            }
            stream_set_timeout($sock, 30);

            $this->expect($sock, 220, 'greeting');
            $this->cmd($sock, 'EHLO localhost', 250, 'EHLO');

            if ($useTls) {
                $this->cmd($sock, 'STARTTLS', 220, 'STARTTLS');
                stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->cmd($sock, 'EHLO localhost', 250, 'EHLO after TLS');
            }

            $this->cmd($sock, 'AUTH LOGIN', 334, 'AUTH LOGIN');
            $this->cmd($sock, base64_encode($user), 334, 'AUTH user');
            $this->cmd($sock, base64_encode($pass), 235, 'AUTH pass');
            $this->cmd($sock, "MAIL FROM:<{$from}>", 250, 'MAIL FROM');
            $this->cmd($sock, "RCPT TO:<{$to}>", 250, 'RCPT TO');
            $this->cmd($sock, 'DATA', 354, 'DATA');

            fwrite($sock, $headers . "\r\n" . $body . "\r\n.\r\n");
            $this->expect($sock, 250, 'message accepted');

            $this->cmd($sock, 'QUIT', 221, 'QUIT');
            fclose($sock);

            $this->logger->info("ConversationalCommerce: SMTP sent successfully to {$to}.");
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('ConversationalCommerce: SMTP send failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Send command and assert expected response code. */
    private function cmd($sock, string $command, int $expectedCode, string $step): string
    {
        fwrite($sock, $command . "\r\n");
        return $this->expect($sock, $expectedCode, $step);
    }

    /** Read response and throw if code doesn't match. */
    private function expect($sock, int $expectedCode, string $step): string
    {
        $response = '';
        while ($line = fgets($sock, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr(trim($response), 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException(
                "SMTP [{$step}] expected {$expectedCode}, got {$code}: " . trim($response)
            );
        }
        return $response;
    }
}
