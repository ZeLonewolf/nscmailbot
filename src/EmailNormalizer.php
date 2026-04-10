<?php

declare(strict_types=1);

/**
 * Normalize HTML-ish booking emails into plain text suitable for line/regex parsing.
 * Does not assume valid HTML; avoids DOM dependency for shared-hosting robustness.
 */
final class EmailNormalizer
{
    /**
     * Convert tags and entities to parseable text; collapse whitespace per line.
     */
    public static function normalize(string $raw): string
    {
        $s = $raw;

        // Normalize common line-break tags to newlines (case-insensitive).
        $s = preg_replace('/<\\s*br\\s*\\/?\\s*>/i', "\n", $s) ?? $s;

        // Paragraph boundaries become newlines.
        $s = preg_replace('/<\\s*\\/\\s*p\\s*>/i', "\n", $s) ?? $s;
        $s = preg_replace('/<\\s*p\\s*>/i', "\n", $s) ?? $s;

        // Strip remaining tags but keep inner text (messy HTML safe).
        $s = strip_tags($s);

        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize Unicode spaces to ASCII space.
        $s = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $s) ?? $s;

        // QP-wrapped club mail often hard-wraps mid-field; merge into one flow for regex parsing.
        $s = preg_replace('/[\r\n]+/', ' ', $s) ?? $s;
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;

        return trim($s);
    }
}
