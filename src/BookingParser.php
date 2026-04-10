<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailNormalizer.php';

/**
 * Parse Newport Ski Club booking notification bodies into a structured event array.
 * Defensive: never throws; returns partial data and parsing_notes on failure.
 */
final class BookingParser
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(string $rawEmailBody): array
    {
        $notes = [];
        $normalized = EmailNormalizer::normalize($rawEmailBody);

        if ($normalized === '') {
            return self::emptyEvent('Empty input after normalization', $normalized, $notes);
        }

        $edited = self::tryParseEditedBooking($normalized, $notes);
        if ($edited !== null) {
            return $edited;
        }

        $eventType = self::detectEventType($normalized, $notes);
        $ref = self::extractLabelValue($normalized, 'Booking Reference:') ?? '';
        $contact = self::extractLabelValue($normalized, 'Contact Name:') ?? '';
        $checkInRaw = self::extractLabelValue($normalized, 'Check-In:') ?? '';
        $checkOutRaw = self::extractLabelValue($normalized, 'Check-Out:') ?? '';
        $url = self::extractBookingUrl($normalized);

        $checkIn = self::parseBookingDateTime($checkInRaw, $notes, 'check_in');
        $checkOut = self::parseBookingDateTime($checkOutRaw, $notes, 'check_out');

        $bunkSection = self::extractBunkSection($normalized, $notes);
        $bunks = [];
        if ($bunkSection !== null) {
            $bunks = self::parseBunkRows($bunkSection, $notes);
        }

        $counts = self::aggregateCounts($bunks);

        $excerpt = self::makeExcerpt($normalized);

        return [
            'event_type' => $eventType,
            'booking_reference' => $ref !== '' ? $ref : null,
            'contact_name' => $contact !== '' ? $contact : null,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'booking_url' => $url !== '' ? $url : null,
            'bunks' => $bunks,
            'counts' => $counts,
            'parsing_notes' => $notes,
            'raw_excerpt' => $excerpt,
        ];
    }

    /**
     * Short "booking edited" notices (no bunk table).
     *
     * @param list<string> $notes
     * @return array<string, mixed>|null
     */
    private static function tryParseEditedBooking(string $normalized, array &$notes): ?array
    {
        if (preg_match('/\bBooking\s+(NP\d+)\s+was\s+edited\s+by\s+(.+?)(?:\s+Check\s+it\s*:|https?:\/\/)/is', $normalized, $m) !== 1) {
            return null;
        }
        $ref = $m[1];
        $contact = trim($m[2]);
        $url = self::extractBookingUrl($normalized);
        $excerpt = self::makeExcerpt($normalized);
        $notes[] = 'parsed as booking-edited notice (no bunk block)';

        return [
            'event_type' => 'EDITED',
            'booking_reference' => $ref,
            'contact_name' => $contact !== '' ? $contact : null,
            'check_in' => null,
            'check_out' => null,
            'booking_url' => $url !== '' ? $url : null,
            'bunks' => [],
            'counts' => ['members' => 0, 'children' => 0, 'guests' => 0, 'other' => 0],
            'parsing_notes' => $notes,
            'raw_excerpt' => $excerpt,
        ];
    }

    /**
     * @param list<string> $notes
     * @return 'BOOKED'|'CANCELLED'|'UNKNOWN'
     */
    private static function detectEventType(string $text, array &$notes): string
    {
        // Confirmed and tentative NORA notices both report as BOOKED in logs and digests.
        if (preg_match('/\bCONFIRMED\s+booking\b/i', $text) === 1) {
            return 'BOOKED';
        }
        if (preg_match('/\bTENTATIVE\s+booking\b/i', $text) === 1) {
            return 'BOOKED';
        }
        if (preg_match('/\bCANCELLED\b/i', $text) === 1 || preg_match('/\bCANCELED\b/i', $text) === 1) {
            return 'CANCELLED';
        }
        $notes[] = 'event_type: no CONFIRMED/TENTATIVE/CANCELLED booking phrase matched';
        return 'UNKNOWN';
    }

    private static function extractLabelValue(string $text, string $label): ?string
    {
        $escaped = preg_quote($label, '/');
        if (preg_match('/' . $escaped . '\s*(.+)/i', $text, $m) !== 1) {
            return null;
        }
        $v = trim($m[1]);
        // Value often runs until next label or line break in normalized single-line blocks.
        $v = preg_split('/\b(?:Booking Reference|Contact Name|Check-In|Check-Out|Bunk Details|Amount Owing|Customer Comment|Guest comments|Access details here):/i', $v)[0];
        $v = trim((string) $v);
        // Strip trailing noise common in check-out lines.
        $v = preg_replace('/\s+or\s+when\s+chores\s+are\s+done.*$/i', '', $v) ?? $v;
        return trim($v);
    }

    private static function extractBookingUrl(string $text): string
    {
        if (preg_match('#(https://bookings\.newportskiclub\.org/[^\s]+)#i', $text, $m) === 1) {
            return rtrim($m[1], '.,;)"\'');
        }
        return '';
    }

    /**
     * Parse strings like "Monday March 30th 2026 @ 11:30" to "Y-m-d H:i" (UTC).
     *
     * @param list<string> $notes
     */
    private static function parseBookingDateTime(string $raw, array &$notes, string $field): ?string
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/\b(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b\s+/i', '', $s) ?? $s;
        $s = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $s) ?? $s;
        $s = str_replace('@', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = trim($s);

        $tz = new DateTimeZone('UTC');
        $dt = DateTimeImmutable::createFromFormat('F j Y H:i', $s, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d H:i');
        }
        $ts = strtotime($s . ' UTC');
        if ($ts !== false) {
            return gmdate('Y-m-d H:i', $ts);
        }
        $notes[] = "{$field}: could not parse datetime from: " . self::truncate($s, 80);
        return null;
    }

    /**
     * @param list<string> $notes
     */
    private static function extractBunkSection(string $normalized, array &$notes): ?string
    {
        $pos = stripos($normalized, 'Bunk Details');
        if ($pos === false) {
            $notes[] = 'bunk_section: Bunk Details anchor not found';
            return null;
        }
        $after = substr($normalized, $pos);
        $stops = [
            'Food Reservations',
            '===============================',
            'Amount Owing:',
            'Customer Comment:',
            'Guest comments:',
            'Access details here:',
        ];
        $cut = strlen($after);
        foreach ($stops as $stop) {
            $p = stripos($after, $stop);
            if ($p !== false && $p < $cut) {
                $cut = $p;
            }
        }
        $section = trim(substr($after, 0, $cut));
        if ($section === '') {
            $notes[] = 'bunk_section: empty after slicing';
            return null;
        }
        return $section;
    }

    /**
     * Strip table header words then split rows on currency amounts.
     *
     * @param list<string> $notes
     * @return list<array<string, mixed>>
     */
    private static function parseBunkRows(string $bunkSection, array &$notes): array
    {
        $body = preg_replace(
            '/^Bunk Details\s+Name\s+Calculated cost(?:\s+Start\s+End)?\s*/i',
            '',
            $bunkSection
        ) ?? $bunkSection;
        $body = trim($body);

        if ($body === '' || preg_match_all('/\$\s*\d+\.\d{2}/', $body, $matches, PREG_OFFSET_CAPTURE) < 1) {
            $notes[] = 'bunk_rows: no currency tokens found';
            return [];
        }

        $rows = [];
        $offsets = $matches[0];
        $n = count($offsets);
        $cursor = 0;
        for ($i = 0; $i < $n; $i++) {
            $off = $offsets[$i][1];
            $priceMatch = $offsets[$i][0];
            $prefix = trim(substr($body, $cursor, $off - $cursor));
            $priceLen = strlen($priceMatch);
            $tailStart = $off + $priceLen;
            $tail = substr($body, $tailStart);
            // Cancellation rows may have M/D/YYYY (optional second) immediately after the price; consume only that tail.
            $consumedAfterPrice = 0;
            if (preg_match('/^\s*((\d{1,2}\/\d{1,2}\/\d{4})(?:\s+(\d{1,2}\/\d{1,2}\/\d{4}))?)/', $tail, $dm) === 1) {
                $consumedAfterPrice = strlen($dm[0]);
            }
            $afterChunk = trim(substr($tail, 0, $consumedAfterPrice));

            $row = self::parseSingleBunkRow($prefix, $priceMatch, $afterChunk, $notes);
            if ($row !== null) {
                $rows[] = $row;
            }
            $cursor = $tailStart + $consumedAfterPrice;
        }

        return $rows;
    }

    /**
     * @param list<string> $notes
     * @return array<string, mixed>|null
     */
    private static function parseSingleBunkRow(string $prefix, string $priceLiteral, string $afterChunk, array &$notes): ?array
    {
        $occupant = self::findLastOccupantName($prefix);
        if ($occupant === null) {
            $notes[] = 'bunk_row: could not find Last, First in chunk: ' . self::truncate($prefix, 120);
            return [
                'bunk' => self::truncate(trim($prefix), 200),
                'occupant_name' => null,
                'category_raw' => null,
                'cost' => $priceLiteral,
                'per_bunk_start' => null,
                'per_bunk_end' => null,
                'classification' => 'other',
            ];
        }

        $occPos = self::lastStringPosition($prefix, $occupant);
        $bunkPart = trim(substr($prefix, 0, $occPos));
        $afterOcc = trim(substr($prefix, $occPos + strlen($occupant)));
        $categoryRaw = self::extractCategoryParenBlock($afterOcc);

        $dates = self::extractMdYDates($afterChunk);

        return [
            'bunk' => $bunkPart,
            'occupant_name' => $occupant,
            'category_raw' => $categoryRaw,
            'cost' => trim($priceLiteral),
            'per_bunk_start' => $dates[0] ?? null,
            'per_bunk_end' => $dates[1] ?? null,
            'classification' => self::classifyCategory($categoryRaw, $afterOcc),
        ];
    }

    private static function findLastOccupantName(string $prefix): ?string
    {
        $pattern = '/\b([A-Za-z][A-Za-z\'-]*(?:\s+[A-Za-z][A-Za-z\'-]*)*,\s*[A-Za-z][A-Za-z\'-]*(?:\s+[A-Za-z][A-Za-z\'-]*)*)\b/u';
        if (preg_match_all($pattern, $prefix, $m, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $last = end($m[1]);
        return is_array($last) ? $last[0] : null;
    }

    private static function lastStringPosition(string $haystack, string $needle): int
    {
        $pos = strrpos($haystack, $needle);
        return $pos !== false ? $pos : 0;
    }

    private static function extractBalancedParens(string $s): ?string
    {
        $s = trim($s);
        if ($s === '' || $s[0] !== '(') {
            return null;
        }
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, 0, $i + 1);
                }
            }
        }
        return null;
    }

    /**
     * Find the first '(' in occupant tail (after NBSP/BOM junk) and take one balanced (...).
     */
    private static function extractCategoryParenBlock(string $afterOcc): ?string
    {
        $s = preg_replace('/^[\s\x{00A0}\x{FEFF}]+/u', '', trim($afterOcc)) ?? trim($afterOcc);
        $pos = strpos($s, '(');
        if ($pos === false) {
            return null;
        }
        if ($pos > 0) {
            $s = substr($s, $pos);
        }

        return self::extractBalancedParens($s);
    }

    /** Text inside the outermost (...); avoids trim() with ')' that strips nested closers. */
    private static function innerCategoryText(?string $categoryRaw): string
    {
        if ($categoryRaw === null || trim($categoryRaw) === '') {
            return '';
        }
        $s = trim($categoryRaw);
        $full = self::extractBalancedParens($s);
        if ($full !== null && strlen($full) >= 2) {
            return trim(substr($full, 1, -1));
        }

        return $s;
    }

    /**
     * @return list<string>
     */
    private static function extractMdYDates(string $afterChunk): array
    {
        if (preg_match_all('/\b(\d{1,2}\/\d{1,2}\/\d{4})\b/', $afterChunk, $m) >= 1) {
            return $m[1];
        }
        return [];
    }

    /**
     * @return 'member'|'child'|'guest'|'other'
     */
    private static function classifyCategory(?string $categoryRaw, string $afterOccFallback = ''): string
    {
        $inner = self::innerCategoryText($categoryRaw);
        if ($inner === '') {
            $inner = self::snippetForGuestHint($afterOccFallback);
        }
        if ($inner === '') {
            return 'other';
        }
        $low = strtolower($inner);

        // NSC "Guest or Social Member (Age 12+)" — must precede \bmember\b ("Social Member" contains "member").
        if (self::looksLikeGuestOrSocialMember($low, $inner)) {
            return 'guest';
        }

        // Combined adult/member-child label: count as member (matches club fixture expectations).
        if (preg_match('/active\s+member\s+or\s+member\s+child/i', $inner) === 1) {
            return 'member';
        }

        if (strpos($low, 'member child') !== false || preg_match('/\bchild\b/', $low) === 1) {
            return 'child';
        }

        if (strpos($low, 'active member') !== false || preg_match('/\bmember\b/', $low) === 1) {
            return 'member';
        }

        return 'other';
    }

    private static function snippetForGuestHint(string $afterOcc): string
    {
        if (preg_match('/Guest\s+or\s+Social\s+Member/i', $afterOcc) === 1) {
            return 'guest or social member';
        }

        return '';
    }

    private static function looksLikeGuestOrSocialMember(string $low, string $inner): bool
    {
        if (strpos($low, 'guest or social') !== false) {
            return true;
        }
        if (strpos($low, 'social member') !== false) {
            return true;
        }
        if (preg_match('/\bguest\b/', $low) === 1 && strpos($low, 'non-guest') === false && strpos($low, 'non guest') === false) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $bunks
     * @return array{members:int,children:int,guests:int,other:int}
     */
    private static function aggregateCounts(array $bunks): array
    {
        $m = $c = $g = $o = 0;
        foreach ($bunks as $row) {
            $cls = $row['classification'] ?? 'other';
            if ($cls === 'unknown') {
                $cls = 'other';
            }
            switch ($cls) {
                case 'member':
                    $m++;
                    break;
                case 'child':
                    $c++;
                    break;
                case 'guest':
                    $g++;
                    break;
                default:
                    $o++;
            }
        }
        return [
            'members' => $m,
            'children' => $c,
            'guests' => $g,
            'other' => $o,
        ];
    }

    private static function makeExcerpt(string $normalized): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        return self::truncate($t, 400);
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }

    /**
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private static function emptyEvent(string $reason, string $normalized, array $notes): array
    {
        $notes[] = $reason;
        return [
            'event_type' => 'UNKNOWN',
            'booking_reference' => null,
            'contact_name' => null,
            'check_in' => null,
            'check_out' => null,
            'booking_url' => null,
            'bunks' => [],
            'counts' => ['members' => 0, 'children' => 0, 'guests' => 0, 'other' => 0],
            'parsing_notes' => $notes,
            'raw_excerpt' => self::makeExcerpt($normalized),
        ];
    }
}
