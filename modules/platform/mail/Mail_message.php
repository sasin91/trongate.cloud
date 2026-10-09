<?php

/**
 * Reads a captured message (RFC 5322 source, as the app sent it) for the
 * Mail tab: the headers worth showing and the plain text and HTML bodies,
 * decoded to UTF-8. Lenient: whatever it cannot read is left out, and the
 * raw source is always there to look at.
 *
 * Framework-free.
 */
final class Mail_message {

    /** Multipart nesting followed before giving up */
    const MAX_DEPTH = 8;

    /**
     * @return array{from: string, to: string, cc: string, reply_to: string, subject: string, date: string, text: string, html: string, attachments: list<string>}
     */
    static function parse(string $raw): array {
        [$headers, $body] = self::split($raw);
        $message = [
            'from' => self::header($headers, 'from'),
            'to' => self::header($headers, 'to'),
            'cc' => self::header($headers, 'cc'),
            'reply_to' => self::header($headers, 'reply-to'),
            'subject' => self::header($headers, 'subject'),
            'date' => self::header($headers, 'date'),
            'text' => '',
            'html' => '',
            'attachments' => [],
        ];

        self::part($headers, $body, $message, 0);

        return $message;
    }

    /**
     * Headers (lowercase name => list of unfolded raw values) and body
     *
     * @return array{0: array<string, list<string>>, 1: string}
     */
    static function split(string $raw): array {
        $raw = str_replace("\r\n", "\n", $raw);
        $end = strpos($raw, "\n\n");
        $head = $end === false ? $raw : substr($raw, 0, $end);
        $body = $end === false ? '' : substr($raw, $end + 2);

        $headers = [];
        $unfolded = preg_replace('/\n[ \t]+/', ' ', $head) ?? $head;

        foreach (explode("\n", $unfolded) as $line) {
            if (preg_match('/^([!-9;-~]+):[ \t]*(.*)$/', $line, $match)) {
                $headers[strtolower($match[1])][] = $match[2];
            }
        }

        return [$headers, $body];
    }

    /**
     * A header's first value, RFC 2047 encoded words decoded to UTF-8
     *
     * @param array<string, list<string>> $headers
     */
    static function header(array $headers, string $name): string {
        $value = $headers[$name][0] ?? '';

        if ($value === '') {
            return '';
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return self::utf8($decoded === false ? $value : $decoded);
    }

    /**
     * Collect the first text/plain and text/html bodies (and attachment
     * names) of one MIME part, following multiparts
     *
     * @param array<string, list<string>> $headers
     * @param array{text: string, html: string, attachments: list<string>} $message
     */
    private static function part(array $headers, string $body, array &$message, int $depth): void {
        [$type, $params] = self::content_type($headers['content-type'][0] ?? 'text/plain');
        $disposition = strtolower($headers['content-disposition'][0] ?? '');

        if (str_starts_with($type, 'multipart/')) {
            if ($depth >= self::MAX_DEPTH || ($params['boundary'] ?? '') === '') {
                return;
            }

            foreach (self::multipart($body, $params['boundary']) as $part) {
                [$part_headers, $part_body] = self::split($part);
                self::part($part_headers, $part_body, $message, $depth + 1);
            }

            return;
        }

        $filename = $params['name'] ?? (preg_match('/filename\*?="?([^";]+)"?/i', $headers['content-disposition'][0] ?? '', $match) ? $match[1] : null);

        if (str_starts_with($disposition, 'attachment') || ($filename !== null && !in_array($type, ['text/plain', 'text/html'], true))) {
            $message['attachments'][] = self::utf8((string) ($filename ?? $type));

            return;
        }

        $field = match ($type) {
            'text/plain' => 'text',
            'text/html' => 'html',
            default => null,
        };

        if ($field === null || $message[$field] !== '') {
            return;
        }

        $decoded = match (strtolower(trim($headers['content-transfer-encoding'][0] ?? ''))) {
            'quoted-printable' => quoted_printable_decode($body),
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            default => $body,
        };

        // Trailing line breaks are the mailer's padding before the next boundary
        $message[$field] = rtrim(self::utf8($decoded, $params['charset'] ?? 'UTF-8'), "\r\n");
    }

    /**
     * Media type (lowercase) and parameters (lowercase names) of a Content-Type
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function content_type(string $value): array {
        $parts = explode(';', $value);
        $type = strtolower(trim((string) array_shift($parts)));
        $params = [];

        if (preg_match_all('/([A-Za-z0-9*_-]+)\s*=\s*("([^"]*)"|[^;\s]+)/', implode(';', $parts), $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $params[strtolower($match[1])] = isset($match[3]) && $match[3] !== '' ? $match[3] : trim($match[2], '"');
            }
        }

        return [$type, $params];
    }

    /**
     * The parts of a multipart body, between its boundary lines
     *
     * @return list<string>
     */
    private static function multipart(string $body, string $boundary): array {
        $parts = [];
        $current = null;

        foreach (explode("\n", $body) as $line) {
            $trimmed = rtrim($line);

            if ($trimmed === '--' . $boundary . '--') {
                break;
            }

            if ($trimmed === '--' . $boundary) {
                if ($current !== null) {
                    $parts[] = $current;
                }

                $current = '';
                continue;
            }

            if ($current !== null) {
                $current .= $line . "\n";
            }
        }

        if ($current !== null) {
            $parts[] = $current;
        }

        // The line break before a boundary belongs to the boundary
        return array_map(fn(string $part) => substr($part, -1) === "\n" ? substr($part, 0, -1) : $part, $parts);
    }

    /**
     * Valid UTF-8, converted from $charset when it names another one
     */
    private static function utf8(string $value, string $charset = 'UTF-8'): string {
        $charset = strtoupper(trim($charset));

        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            try {
                $value = mb_convert_encoding($value, 'UTF-8', $charset);
            } catch (ValueError) {
                // An unknown charset: keep the bytes, scrubbed below
            }
        }

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }
}
