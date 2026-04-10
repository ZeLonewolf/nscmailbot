<?php

declare(strict_types=1);

/**
 * Pure formatters: activity log line and digest summary from parsed event arrays.
 */
final class BookingFormatter
{
    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /**
     * @param array<string, mixed> $event from BookingParser::parse
     * @param string $processedAtStamp e.g. mm/dd/yy 12-hour time (Eastern), from LogTimestamp::now()
     */
    public static function formatActivityLine(array $event, string $processedAtStamp): string
    {
        $type = $event['event_type'] ?? 'UNKNOWN';
        if ($type === 'UNKNOWN' && !empty($event['unknown_inbound_external'])) {
            $from = self::oneLineLogToken($event['envelope_from'] ?? '');
            $subj = self::oneLineLogToken($event['envelope_subject'] ?? '');

            return sprintf(
                '%s | UNKNOWN | from=%s | subject=%s',
                $processedAtStamp,
                $from !== '' ? $from : '-',
                $subj !== '' ? $subj : '-'
            );
        }
        $contact = self::dashIfEmpty($event['contact_name'] ?? null);
        $cin = self::formatUsDateTime($event['check_in'] ?? null);
        $cout = self::formatUsDateTime($event['check_out'] ?? null);
        $counts = $event['counts'] ?? [];
        $occupancy = self::occupancyPlainList($counts);

        $base = sprintf(
            '%s | %s | %s | %s -> %s',
            $processedAtStamp,
            $type,
            $contact,
            $cin,
            $cout
        );
        if ($occupancy === '') {
            return $base;
        }

        return $base . ' | ' . $occupancy;
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
        $contact = (string) ($event['contact_name'] ?? '');
        if ($contact === '') {
            $contact = '-';
        }

        $stay = self::digestStaySummary($event['check_in'] ?? null, $event['check_out'] ?? null);
        $summary = self::digestOccupancySummary($event['counts'] ?? []);

        return sprintf('%s: %s, %s, %s', $type, $contact, $stay, $summary);
    }

    /** @param array<string, int> $counts */
    private static function countOther(array $counts): int
    {
        if (isset($counts['other'])) {
            return (int) $counts['other'];
        }
        // Legacy parsed events
        if (isset($counts['unknown'])) {
            return (int) $counts['unknown'];
        }

        return 0;
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

    /** US style M/d/y hh:mm am/pm from internal Y-m-d H:i. */
    private static function formatUsDateTime(mixed $ymdHi): string
    {
        $dt = self::parseYmdHi($ymdHi);
        if ($dt === null) {
            return '-';
        }

        return $dt->format('n/j/Y g:i A');
    }

    /** e.g. 3/6 (2 nights) from check-in/out dates (calendar-night count). */
    private static function digestStaySummary(mixed $checkIn, mixed $checkOut): string
    {
        $a = self::parseYmdHi($checkIn);
        $b = self::parseYmdHi($checkOut);
        if ($a === null || $b === null) {
            return '-';
        }
        $nights = self::nightCount($a, $b);
        if ($nights === null) {
            return '-';
        }
        $startUs = $a->format('n/j');
        $nLabel = $nights === 1 ? '1 night' : "{$nights} nights";

        return "{$startUs} ({$nLabel})";
    }

    private static function nightCount(DateTimeImmutable $checkIn, DateTimeImmutable $checkOut): ?int
    {
        $d0 = $checkIn->setTime(0, 0, 0);
        $d1 = $checkOut->setTime(0, 0, 0);
        if ($d1 < $d0) {
            return null;
        }
        $interval = $d0->diff($d1);

        return (int) $interval->format('%a');
    }

    private static function parseYmdHi(mixed $v): ?DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $s, self::utc());
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s, self::utc());
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }

    /**
     * Non-zero categories only, e.g. "1 member, 2 guests".
     *
     * @param array<string, int> $counts
     * @return list<string>
     */
    private static function occupancyNonZeroParts(array $counts): array
    {
        $m = (int) ($counts['members'] ?? 0);
        $c = (int) ($counts['children'] ?? 0);
        $g = (int) ($counts['guests'] ?? 0);
        $o = self::countOther($counts);
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
        if ($o > 0) {
            $parts[] = $o === 1 ? '1 other' : "{$o} other";
        }

        return $parts;
    }

    /** Activity log: plain list; omit entire tail when all counts are zero. */
    private static function occupancyPlainList(array $counts): string
    {
        $parts = self::occupancyNonZeroParts($counts);

        return $parts === [] ? '' : implode(', ', $parts);
    }

    /** Digest: same wording, but explain when nothing was counted. */
    private static function digestOccupancySummary(array $counts): string
    {
        $parts = self::occupancyNonZeroParts($counts);
        if ($parts === []) {
            return '0 occupants parsed';
        }

        return implode(', ', $parts);
    }
}
