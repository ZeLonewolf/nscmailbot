<?php

declare(strict_types=1);

/**
 * Extracts a single text body from raw RFC822-style messages (e.g. saved .eml).
 * Handles multipart/alternative and quoted-printable so BookingParser sees the same
 * content as the club's text/plain parts (including embedded <br> markup).
 */
final class EmlBodyExtractor
{
    /**
     * If input looks like a full email, return the best body part; otherwise return input unchanged.
     */
    /**
     * Top-level headers for logging / routing (decoded where possible).
     *
     * @return array{from: string, to: string, delivered_to: string, subject: string, return_path: string}|null
     */
    public static function extractEnvelope(string $raw): ?array
    {
        if (!self::looksLikeMimeMessage($raw)) {
            return null;
        }
        [$headerBlock, ] = self::splitHeadersAndBody($raw);
        if ($headerBlock === '') {
            return null;
        }

        return [
            'from' => self::decodeMimeHeader(self::getHeaderValue($headerBlock, 'From') ?? ''),
            'to' => self::decodeMimeHeader(self::getHeaderValue($headerBlock, 'To') ?? ''),
            'delivered_to' => self::decodeMimeHeader(self::getHeaderValue($headerBlock, 'Delivered-To') ?? ''),
            'subject' => self::decodeMimeHeader(self::getHeaderValue($headerBlock, 'Subject') ?? ''),
            'return_path' => self::decodeMimeHeader(self::getHeaderValue($headerBlock, 'Return-Path') ?? ''),
        ];
    }

    public static function extractBodyForParser(string $raw): string
    {
        if (!self::looksLikeMimeMessage($raw)) {
            return $raw;
        }
        [$headerBlock, $body] = self::splitHeadersAndBody($raw);
        if ($headerBlock === '') {
            return $raw;
        }
        $contentType = self::getHeaderValue($headerBlock, 'Content-Type');
        if ($contentType === null) {
            return self::decodePartBody($body, null);
        }
        if (stripos($contentType, 'multipart/') === 0) {
            $boundary = self::extractBoundary($contentType);
            if ($boundary === null) {
                return self::decodePartBody($body, null);
            }

            return self::extractFromMultipart($body, $boundary);
        }
        $cte = self::getHeaderValue($headerBlock, 'Content-Transfer-Encoding');

        return self::decodePartBody($body, $cte);
    }

    private static function looksLikeMimeMessage(string $raw): bool
    {
        $head = substr($raw, 0, 4096);

        return preg_match('/^(?:Return-Path:|Delivered-To:|MIME-Version:|From:|Received:)/mi', $head) === 1;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitHeadersAndBody(string $raw): array
    {
        if (preg_match("/\r\n\r\n|\n\n/", $raw, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return ['', $raw];
        }
        $sepLen = strlen($m[0][0]);
        $pos = $m[0][1];
        $headerRaw = substr($raw, 0, $pos);
        $body = substr($raw, $pos + $sepLen);

        return [self::unfoldHeaderBlock($headerRaw), $body];
    }

    private static function unfoldHeaderBlock(string $headerRaw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $headerRaw) ?: [];
        $merged = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $merged !== []) {
                $merged[count($merged) - 1] .= ' ' . trim($line);
            } else {
                $merged[] = $line;
            }
        }

        return implode("\n", $merged);
    }

    private static function getHeaderValue(string $headerBlock, string $name): ?string
    {
        $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+)$/mi';
        if (preg_match($pattern, $headerBlock, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    private static function decodeMimeHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                return trim($decoded);
            }
        }

        return $value;
    }

    private static function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/\bboundary\s*=\s*"([^"]+)"/i', $contentType, $m) === 1) {
            return $m[1];
        }
        if (preg_match("/\bboundary\s*=\s*'([^']+)'/i", $contentType, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\bboundary\s*=\s*([^\s;]+)/i', $contentType, $m) === 1) {
            return trim($m[1], '"\'');
        }

        return null;
    }

    private static function extractFromMultipart(string $body, string $boundary): string
    {
        $delim = '--' . $boundary;
        $parts = explode($delim, $body);
        $plain = [];
        $html = [];
        foreach ($parts as $chunk) {
            $chunk = ltrim($chunk);
            if ($chunk === '' || strpos($chunk, '--') === 0) {
                continue;
            }
            [$pHeaders, $pBody] = self::splitHeadersAndBody($chunk);
            $ct = self::getHeaderValue($pHeaders, 'Content-Type') ?? 'text/plain';
            $cte = self::getHeaderValue($pHeaders, 'Content-Transfer-Encoding');
            $decoded = self::decodePartBody($pBody, $cte);
            if (stripos($ct, 'text/plain') === 0) {
                $plain[] = $decoded;
            } elseif (stripos($ct, 'text/html') === 0) {
                $html[] = $decoded;
            }
        }

        return self::pickBestAlternative($plain, $html);
    }

    /**
     * @param list<string> $plain
     * @param list<string> $html
     */
    private static function pickBestAlternative(array $plain, array $html): string
    {
        foreach ($plain as $p) {
            if (self::scoreBookingLikelihood($p) >= 2) {
                return $p;
            }
        }
        foreach ($html as $h) {
            if (self::scoreBookingLikelihood($h) >= 2) {
                return $h;
            }
        }
        if ($plain !== []) {
            return $plain[0];
        }
        if ($html !== []) {
            return $html[0];
        }

        return '';
    }

    private static function scoreBookingLikelihood(string $s): int
    {
        $score = 0;
        if (stripos($s, 'Booking Reference:') !== false) {
            $score += 2;
        }
        if (stripos($s, 'Bunk Details') !== false) {
            $score += 2;
        }
        if (preg_match('/Newport\s+Ski\s+Club/i', $s) === 1) {
            $score += 1;
        }
        if (preg_match('/bookings\.newportskiclub\.org/i', $s) === 1) {
            $score += 1;
        }
        if (preg_match('/\bBooking\s+NP\d+\s+was\s+edited\b/i', $s) === 1) {
            $score += 2;
        }

        return $score;
    }

    private static function decodePartBody(string $body, ?string $contentTransferEncoding): string
    {
        $body = self::unfoldQuotedPrintableSoftBreaks($body);
        $enc = $contentTransferEncoding !== null ? strtolower(trim($contentTransferEncoding)) : '';
        if ($enc === 'quoted-printable' || strpos($body, '=\n') !== false || strpos($body, "=\r\n") !== false) {
            $body = quoted_printable_decode($body);
        }
        if ($enc === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }

        return $body;
    }

    private static function unfoldQuotedPrintableSoftBreaks(string $s): string
    {
        $s = str_replace("=\r\n", '', $s);
        $s = str_replace("=\n", '', $s);

        return $s;
    }
}
