<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Model\Channel\Email;

/**
 * Minimal IMAP client using raw PHP streams.
 * Supports direct SSL (port 993) and STARTTLS (port 143).
 * No ext-imap or third-party library required.
 */
class NativeImapClient
{
    /** @var resource|null */
    private $socket = null;
    private int $tag = 0;

    public function connect(string $host, int $port, string|false $encryption): void
    {
        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $uri          = ($encryption === 'ssl' ? 'ssl' : 'tcp') . "://$host:$port";
        $this->socket = stream_socket_client($uri, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

        if (!$this->socket) {
            throw new \RuntimeException("Cannot connect to $host:$port – $errstr ($errno)");
        }
        stream_set_timeout($this->socket, 30);
        $this->readLine(); // server greeting

        if ($encryption === 'starttls') {
            $this->cmd('STARTTLS');
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS negotiation failed.');
            }
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->cmd(sprintf('LOGIN %s %s', $this->q($user), $this->q($pass)));
    }

    public function select(string $mailbox): void
    {
        $this->cmd('SELECT ' . $this->q($mailbox));
    }

    /** @return int[] UIDs matching SINCE date */
    public function searchSince(\DateTimeInterface $since): array
    {
        $date  = strtoupper($since->format('d-M-Y'));
        $lines = $this->cmd("UID SEARCH SINCE $date");
        foreach ($lines as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $parts = array_filter(array_map('intval', explode(' ', trim(substr($line, 8)))));
                return array_values($parts);
            }
        }
        return [];
    }

    /** @return array<string, mixed> parsed message data */
    public function fetchMessage(int $uid): array
    {
        $lines = $this->cmd("UID FETCH $uid (RFC822)");
        $raw   = $this->extractLiteral($lines);
        return $this->parseRaw($raw);
    }

    public function logout(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, 'T' . str_pad((string)(++$this->tag), 4, '0', STR_PAD_LEFT) . " LOGOUT\r\n");
            fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── IMAP protocol helpers ──────────────────────────────────────────────

    /** @return string[] untagged response lines */
    private function cmd(string $command): array
    {
        $tag = 'T' . str_pad((string)(++$this->tag), 4, '0', STR_PAD_LEFT);
        fwrite($this->socket, "$tag $command\r\n");

        $lines = [];
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, "$tag OK")) {
                break;
            }
            if (str_starts_with($line, "$tag NO") || str_starts_with($line, "$tag BAD")) {
                throw new \RuntimeException("IMAP error for [$command]: $line");
            }
            // Literal string {n} → read n bytes immediately after
            if (preg_match('/\{(\d+)\}$/', rtrim($line), $m)) {
                $lines[]  = rtrim($line);
                $size     = (int)$m[1];
                $literal  = '';
                while (strlen($literal) < $size) {
                    $chunk = fread($this->socket, $size - strlen($literal));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $literal .= $chunk;
                }
                $lines[] = $literal;
            } else {
                $lines[] = rtrim($line);
            }
        }
        return $lines;
    }

    private function readLine(): string
    {
        $line = fgets($this->socket, 65536);
        return $line === false ? '' : $line;
    }

    private function extractLiteral(array $lines): string
    {
        for ($i = 0, $n = count($lines); $i < $n - 1; $i++) {
            if (preg_match('/\{\d+\}$/', $lines[$i])) {
                return $lines[$i + 1];
            }
        }
        return implode("\r\n", $lines);
    }

    private function q(string $v): string
    {
        return '"' . addcslashes($v, '"\\') . '"';
    }

    // ── Message parsing ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseRaw(string $raw): array
    {
        [$hSection, $bSection] = $this->splitHB($raw);
        $headers   = $this->parseHeaders($hSection);

        $messageId = trim($headers['message-id'] ?? uniqid('cc-', true), '<> ');
        $inReplyTo = trim($headers['in-reply-to'] ?? '', '<> ');

        return [
            'message_id'   => $messageId,
            'thread_id'    => $inReplyTo ?: $messageId,
            'from'         => $this->decodeHeader($headers['from'] ?? ''),
            'subject'      => $this->decodeHeader($headers['subject'] ?? ''),
            'date'         => $headers['date'] ?? date('r'),
            'body'         => $this->extractPlainBody($raw),
            'attachments'  => $this->extractAttachments($raw),
            'is_auto_reply' => $this->isAutoReplyByHeaders($headers),
        ];
    }

    private function isAutoReplyByHeaders(array $headers): bool
    {
        $autoSubmitted = strtolower($headers['auto-submitted'] ?? '');
        if (in_array($autoSubmitted, ['auto-replied', 'auto-generated'], true)) {
            return true;
        }
        if (isset($headers['x-autoreply']) || isset($headers['x-autorespond'])) {
            return true;
        }
        $precedence = strtolower($headers['precedence'] ?? '');
        return in_array($precedence, ['bulk', 'junk', 'auto-reply'], true);
    }

    /** @return array{0:string, 1:string} */
    private function splitHB(string $raw): array
    {
        foreach (["\r\n\r\n", "\n\n"] as $sep) {
            $pos = strpos($raw, $sep);
            if ($pos !== false) {
                return [substr($raw, 0, $pos), substr($raw, $pos + strlen($sep))];
            }
        }
        return [$raw, ''];
    }

    /** @return array<string, string> */
    private function parseHeaders(string $section): array
    {
        // Unfold multi-line headers (RFC 2822)
        $section = preg_replace('/\r?\n([ \t]+)/', ' ', $section) ?? $section;
        $headers = [];
        foreach (preg_split('/\r?\n/', $section) as $line) {
            if (($colon = strpos($line, ':')) !== false) {
                $name            = strtolower(trim(substr($line, 0, $colon)));
                $headers[$name]  = trim(substr($line, $colon + 1));
            }
        }
        return $headers;
    }

    private function extractPlainBody(string $raw): string
    {
        // 1. Prefer text/plain — strip quoted reply lines (>)
        $plain = $this->extractTypedPart($raw, 'text/plain');
        if ($plain !== null) {
            return $this->stripQuotedReply(trim($plain));
        }

        // 2. HTML-only email (e.g. Thunderbird HTML mode) — strip markup and quotes
        $html = $this->extractTypedPart($raw, 'text/html');
        if ($html !== null) {
            return $this->htmlToPlain($html);
        }

        // 3. Fallback: plain body after header block
        [, $body] = $this->splitHB($raw);
        return $this->stripQuotedReply(trim($body));
    }

    private function extractTypedPart(string $raw, string $contentType): ?string
    {
        $escaped = preg_quote($contentType, '/');
        if (!preg_match('/Content-Type:\s*' . $escaped . '[^\r\n]*/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $after = substr($raw, $m[0][1] + strlen($m[0][0]));
        $enc   = 'quoted-printable';
        if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $after, $em)) {
            $enc = strtolower($em[1]);
        }
        $pos = strpos($after, "\r\n\r\n");
        $skip = $pos !== false ? $pos + 4 : null;
        if ($skip === null) {
            $pos2 = strpos($after, "\n\n");
            $skip = $pos2 !== false ? $pos2 + 2 : null;
        }
        if ($skip === null) {
            return null;
        }
        $body = substr($after, $skip);
        $body = preg_split('/\r?\n--/', $body)[0]; // stop at next MIME boundary
        return $this->decodeTransfer($body, $enc);
    }

    /**
     * Convert an HTML email body to clean plain text.
     * Strips quoted reply blocks (blockquote, moz-cite-prefix, gmail_quote, Outlook divRplyFwdMsg)
     * and client signatures before removing all markup.
     */
    private function htmlToPlain(string $html): string
    {
        // Remove Thunderbird cite prefix ("On … wrote:")
        $html = preg_replace(
            '/<div[^>]*class="[^"]*moz-cite-prefix[^"]*"[^>]*>.*?<\/div>/is', '', $html
        ) ?? $html;
        // Remove Thunderbird signature
        $html = preg_replace(
            '/<div[^>]*class="[^"]*moz-signature[^"]*"[^>]*>.*?<\/div>/is', '', $html
        ) ?? $html;
        // Remove Gmail quoted block
        $html = preg_replace(
            '/<div[^>]*class="[^"]*gmail_quote[^"]*"[^>]*>.*?<\/div>/is', '', $html
        ) ?? $html;
        // Remove Outlook reply separator and everything after it
        $html = preg_replace(
            '/<div[^>]*id="divRplyFwdMsg"[^>]*>.*$/is', '', $html
        ) ?? $html;
        // Remove any blockquote (universal quoted-reply container)
        $html = preg_replace(
            '/<blockquote[^>]*>.*?<\/blockquote>/is', '', $html
        ) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return $this->stripQuotedReply(trim($text));
    }

    /**
     * Remove classic quoted-reply content from plain text:
     * lines starting with >, "On … wrote:" separators, and "--- Original Message ---" markers.
     */
    private function stripQuotedReply(string $text): string
    {
        $lines  = preg_split('/\r?\n/', $text) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            // RFC 3676 signature delimiter: "-- " or "--" on its own line
            if ($line === '-- ' || $line === '--') {
                break;
            }
            // Quoted line
            if (str_starts_with($trimmed, '>')) {
                break;
            }
            // "On ... wrote:" (single-line variant used by Thunderbird/Outlook)
            if (preg_match('/^(On|Am|Le|El)\s.+?wrote:\s*$/is', trim($line))) {
                break;
            }
            // "---- Original Message ----" separator
            if (preg_match('/^-{2,}\s*(Original Message|Ursprüngliche Nachricht|Original-Nachricht)/i', trim($line))) {
                break;
            }
            $result[] = $line;
        }
        while (!empty($result) && trim(end($result)) === '') {
            array_pop($result);
        }
        return implode("\n", $result);
    }

    /**
     * Extract file attachments from a MIME email.
     *
     * @return array<int, array{filename: string, content_type: string, data: string}>
     */
    private function extractAttachments(string $raw): array
    {
        [$hSection,] = $this->splitHB($raw);
        $headers     = $this->parseHeaders($hSection);
        $ct          = $headers['content-type'] ?? '';

        // Only multipart messages can have attachments
        if (!preg_match('/multipart\//i', $ct)) {
            return [];
        }

        // Extract boundary parameter
        if (!preg_match('/boundary="?([^";\r\n]+)"?/i', $ct, $bm)) {
            return [];
        }
        $boundary    = trim($bm[1]);
        $parts       = $this->splitOnBoundary($raw, $boundary);
        $attachments = [];

        foreach ($parts as $part) {
            [$ph,] = $this->splitHB($part);
            $ph    = $this->parseHeaders($ph);

            // MIME type without parameters
            $partCt = strtolower(trim(preg_split('/[;\s]/', $ph['content-type'] ?? '')[0] ?? ''));
            $disp   = $ph['content-disposition'] ?? '';

            // Recurse into nested multipart containers
            if (str_starts_with($partCt, 'multipart/')) {
                $attachments = array_merge($attachments, $this->extractAttachments($part));
                continue;
            }

            // Attachment if disposition says so, or if content-type is a known file type
            $isAttachment = (bool) preg_match('/^attachment/i', $disp);
            if (!$isAttachment) {
                foreach ([
                    'application/pdf',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument',
                    'application/msword',
                    'application/octet-stream',
                    'text/csv',
                ] as $prefix) {
                    if (str_starts_with($partCt, $prefix)) {
                        $isAttachment = true;
                        break;
                    }
                }
            }
            if (!$isAttachment) {
                continue;
            }

            // Filename: try Content-Disposition, then Content-Type name=
            $filename = '';
            if (preg_match('/filename\*?=(?:"([^"]+)"|([^\s;]+))/i', $disp, $fm)) {
                $filename = $this->decodeHeader(trim($fm[1] ?: $fm[2], "' "));
            }
            if (!$filename && preg_match('/name\*?=(?:"([^"]+)"|([^\s;]+))/i', $ph['content-type'] ?? '', $nm)) {
                $filename = $this->decodeHeader(trim($nm[1] ?: $nm[2], "' "));
            }
            if (!$filename) {
                $extMap   = [
                    'application/pdf'   => 'pdf',
                    'application/vnd.ms-excel' => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/msword' => 'doc',
                    'text/csv'           => 'csv',
                ];
                $filename = 'attachment.' . ($extMap[$partCt] ?? 'bin');
            }

            $enc = strtolower(trim($ph['content-transfer-encoding'] ?? '7bit'));
            [, $partBody] = $this->splitHB($part);

            // Keep base64 as-is (just strip whitespace); re-encode everything else
            if ($enc === 'base64') {
                $data = str_replace(["\r", "\n", ' '], '', $partBody);
            } else {
                $data = base64_encode($this->decodeTransfer($partBody, $enc));
            }

            $attachments[] = [
                'filename'     => $filename,
                'content_type' => $partCt,
                'data'         => $data,
            ];
        }

        return $attachments;
    }

    /**
     * Split a MIME message body on its boundary, returning individual part strings
     * (each starting with the part's own headers).
     *
     * @return string[]
     */
    private function splitOnBoundary(string $raw, string $boundary): array
    {
        [, $body] = $this->splitHB($raw);

        // Normalize to \n for reliable splitting
        $body      = str_replace("\r\n", "\n", $body);
        $delimiter = "\n--" . $boundary;

        // Prepend \n so the very first boundary is matched by the delimiter
        $segments = explode($delimiter, "\n" . $body);
        array_shift($segments); // discard preamble before first boundary

        $result = [];
        foreach ($segments as $segment) {
            // Closing boundary: --boundary-- → segment starts with "--"
            if (str_starts_with(ltrim($segment, "\r"), '--')) {
                break;
            }
            // Strip the trailing newline that follows the boundary line itself
            $part = ltrim($segment, "\r\n");
            if ($part !== '') {
                $result[] = $part;
            }
        }
        return $result;
    }

    private function decodeTransfer(string $data, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string)base64_decode(str_replace(["\r", "\n"], '', $data)),
            'quoted-printable' => quoted_printable_decode($data),
            default            => $data,
        };
    }

    private function decodeHeader(string $value): string
    {
        // RFC 2047: =?charset?B/Q?encoded?=
        return preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            static function (array $m): string {
                $text = strtoupper($m[2]) === 'B'
                    ? base64_decode($m[3])
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                return mb_convert_encoding($text, 'UTF-8', $m[1]);
            },
            $value
        ) ?? $value;
    }
}
