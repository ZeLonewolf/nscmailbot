<?php

declare(strict_types=1);

/**
 * Pure formatters: activity log line and digest summary from parsed event arrays.
 */
final class BookingFormatter
{
    /**
     * @param array<string, mixed> $event from BookingParser::parse
     */
    public static function formatActivityLine(array $event, string $processedAtIso): string
    {
        $type = $event['event_type'] ?? 'UNKNOWN';
        if ($type === 'UNKNOWN' && !empty($event['unknown_inbound_external'])) {
            $from = self::oneLineLogToken($event['envelope_from'] ?? '');
            $subj = self::oneLineLogToken($event['envelope_subject'] ?? '');

            return sprintf(
                '%s | UNKNOWN | from=%s | subject=%s',
                $processedAtIso,
                $from !== '' ? $from : '-',
                $subj !== '' ? $subj : '-'
            );
        }
        $ref = self::dashIfEmpty($event['booking_reference'] ?? null);
        $contact = self::dashIfEmpty($event['contact_name'] ?? null);
        $cin = self::formatDateTimeSlot($event['check_in'] ?? null);
        $cout = self::formatDateTimeSlot($event['check_out'] ?? null);
        $counts = $event['counts'] ?? ['members' => 0, 'children' => 0, 'guests' => 0, 'unknown' => 0];

        return sprintf(
            '%s | %s | %s | %s | %s -> %s | members=%d children=%d guests=%d unknown=%d',
            $processedAtIso,
            $type,
            $ref,
            $contact,
            $cin,
            $cout,
            (int) ($counts['members'] ?? 0),
            (int) ($counts['children'] ?? 0),
            (int) ($counts['guests'] ?? 0),
            (int) ($counts['unknown'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function formatDigestLine(array $event): string
    {
        $type = $event['event_type'] ?? 'UNKNOWN';
        if ($type === 'UNKNOWN' && !empty($event['unknown_inbound_external'])) {
            $from = self::oneLineLogToken($event['envelope_from'] ?? '');
            $subj = self::oneLineLogToken($event['envelope_subject'] ?? '');
            if ($from === '') {
                $from = '-';
            }
            if ($subj === '') {
                $subj = '-';
            }

            return "UNKNOWN: {$from}, {$subj}";
        }
        $ref = (string) ($event['booking_reference'] ?? '');
        $contact = (string) ($event['contact_name'] ?? '');
        if ($ref === '') {
            $ref = '-';
        }
        if ($contact === '') {
            $contact = '-';
        }

        $range = self::shortDateRange($event['check_in'] ?? null, $event['check_out'] ?? null);
        $summary = self::countSummary($event['counts'] ?? []);

        return sprintf('%s: %s %s, %s, %s', $type, $ref, $contact, $range, $summary);
    }

    /** Single-line safe for append-only logs (no pipes / newlines). */
    private static function oneLineLogToken(string $s): string
    {
        $s = str_replace(["\r", "\n", '|'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }

    private static function dashIfEmpty(mixed $v): string
    {
        if ($v === null) {
            return '-';
        }
        $s = trim((string) $v);
        return $s === '' ? '-' : $s;
    }

    private static function formatDateTimeSlot(mixed $ymdHi): string
    {
        if ($ymdHi === null || $ymdHi === '') {
            return '-';
        }
        $s = trim((string) $ymdHi);
        return $s === '' ? '-' : $s;
    }

    /**
     * Examples: Mar 30-Mar 31, Apr 3-Apr 5
     */
    private static function shortDateRange(mixed $checkIn, mixed $checkOut): string
    {
        $a = self::parseYmdHi($checkIn);
        $b = self::parseYmdHi($checkOut);
        if ($a === null && $b === null) {
            return '-';
        }
        if ($a === null) {
            return self::fmtMd($b);
        }
        if ($b === null) {
            return self::fmtMd($a);
        }
        return self::fmtMd($a) . '-' . self::fmtMd($b);
    }

    private static function parseYmdHi(mixed $v): ?DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $s, new DateTimeZone('UTC'));
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s, new DateTimeZone('UTC'));
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }

    private static function fmtMd(?DateTimeImmutable $dt): string
    {
        if ($dt === null) {
            return '-';
        }
        return $dt->format('M j');
    }

    /**
     * @param array<string, int> $counts
     */
    private static function countSummary(array $counts): string
    {
        $m = (int) ($counts['members'] ?? 0);
        $c = (int) ($counts['children'] ?? 0);
        $g = (int) ($counts['guests'] ?? 0);
        $u = (int) ($counts['unknown'] ?? 0);
        if ($m === 0 && $c === 0 && $g === 0 && $u === 0) {
            return '0 occupants parsed';
        }
        $parts = [];
        if ($m > 0) {
            $parts[] = $m === 1 ? '1 member' : "{$m} members";
        }
        if ($c > 0) {
            $parts[] = $c === 1 ? '1 child' : "{$c} children";
        }
        if ($g > 0) {
            $parts[] = $g === 1 ? '1 guest' : "{$g} guests";
        }
        if ($u > 0) {
            $parts[] = $u === 1 ? '1 unknown' : "{$u} unknown";
        }
        return implode(', ', $parts);
    }
}
