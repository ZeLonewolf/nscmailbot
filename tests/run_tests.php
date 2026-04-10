<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/EmlBodyExtractor.php';
require_once $root . '/src/RawEmailEnvelope.php';
require_once $root . '/src/EmailNormalizer.php';
require_once $root . '/src/BookingParser.php';
require_once $root . '/src/BookingFormatter.php';
require_once $root . '/src/LogWriter.php';

$processedAt = '2026-04-10T08:42:11Z';

/**
 * @return array<string, mixed>
 */
function parse_full_message(string $raw): array
{
    $envelope = EmlBodyExtractor::extractEnvelope($raw);
    $body = EmlBodyExtractor::extractBodyForParser($raw);

    return RawEmailEnvelope::enrichParsedEvent(BookingParser::parse($body), $envelope);
}

function assert_eq($a, $b, string $msg): void
{
    if ($a !== $b) {
        fwrite(STDERR, "FAIL: {$msg}\n  expected: " . var_export($b, true) . "\n  actual:   " . var_export($a, true) . "\n");
        exit(1);
    }
}

// --- LogWriter ---
$tmpLog = sys_get_temp_dir() . '/nscmailbot_test_' . bin2hex(random_bytes(4)) . '.log';
@unlink($tmpLog);
$line = 'test line with' . "\n" . 'breaks';
assert_eq(LogWriter::appendLine($tmpLog, $line), true, 'LogWriter append');
$read = (string) file_get_contents($tmpLog);
assert_eq($read, "test line with breaks\n", 'LogWriter sanitizes newlines');
@unlink($tmpLog);

// --- Real .eml samples (multipart + quoted-printable) ---
$emlDir = $root . '/sample-emails';
$emlFiles = [
    'Cancelled Booking from 3 6 2026 to 3 7 2026.eml' => [
        'event_type' => 'CANCELLED',
        'booking_reference' => 'NP008156',
        'contact_name' => 'Brian Sperlongano',
        'check_in' => '2026-03-06 11:30',
        'check_out' => '2026-03-08 11:00',
        'members' => 1,
        'guests' => 0,
    ],
    'Notification of tentative booking from 2 23 2026 to 2 23 2026.eml' => [
        'event_type' => 'BOOKED',
        'booking_reference' => 'NP008171',
        'contact_name' => 'Brian Sperlongano',
        'check_in' => '2026-02-23 11:30',
        'check_out' => '2026-02-24 11:00',
        'members' => 1,
        'guests' => 1,
    ],
    'Booking NP008344 from 3 13 2026 to 3 15 2026 was edited, status TENTATIVE,.eml' => [
        'event_type' => 'EDITED',
        'booking_reference' => 'NP008344',
        'contact_name' => 'Brian Sperlongano',
    ],
    'Wait list request Feb 20-21.eml' => [
        'event_type' => 'UNKNOWN',
        'booking_reference' => null,
    ],
];

foreach ($emlFiles as $file => $expect) {
    $path = $emlDir . '/' . $file;
    if (!is_readable($path)) {
        fwrite(STDERR, "SKIP: missing sample {$path}\n");
        continue;
    }
    $raw = (string) file_get_contents($path);
    $ev = parse_full_message($raw);
    $label = "eml: {$file}";
    assert_eq($ev['event_type'], $expect['event_type'], "{$label} event_type");
    if (array_key_exists('booking_reference', $expect)) {
        assert_eq($ev['booking_reference'], $expect['booking_reference'], "{$label} booking_reference");
    }
    if (array_key_exists('contact_name', $expect)) {
        assert_eq($ev['contact_name'], $expect['contact_name'], "{$label} contact_name");
    }
    if (isset($expect['check_in'])) {
        assert_eq($ev['check_in'], $expect['check_in'], "{$label} check_in");
    }
    if (isset($expect['check_out'])) {
        assert_eq($ev['check_out'], $expect['check_out'], "{$label} check_out");
    }
    if (isset($expect['members'])) {
        assert_eq($ev['counts']['members'], $expect['members'], "{$label} members");
    }
    if (isset($expect['guests'])) {
        assert_eq($ev['counts']['guests'], $expect['guests'], "{$label} guests");
    }
    if ($expect['event_type'] === 'EDITED') {
        assert_eq(
            strpos((string) $ev['booking_url'], 'NP008344') !== false,
            true,
            "{$label} booking_url contains ref"
        );
    }
    if ($file === 'Wait list request Feb 20-21.eml') {
        assert_eq($ev['unknown_inbound_external'] ?? false, true, "{$label} unknown_inbound_external");
        $expAct = $processedAt . ' | UNKNOWN | from="Brian M. Sperlongano" <zelonewolf@gmail.com> | subject=Wait list request Feb 20-21';
        assert_eq(BookingFormatter::formatActivityLine($ev, $processedAt), $expAct, "{$label} activity simplified");
        $expDig = 'UNKNOWN: "Brian M. Sperlongano" <zelonewolf@gmail.com>, Wait list request Feb 20-21';
        assert_eq(BookingFormatter::formatDigestLine($ev), $expDig, "{$label} digest simplified");
    }
    if ($file === 'Cancelled Booking from 3 6 2026 to 3 7 2026.eml') {
        $expAct = $processedAt . ' | CANCELLED | Brian Sperlongano | 3/6/2026 11:30 AM -> 3/8/2026 11:00 AM | 1 member';
        assert_eq(BookingFormatter::formatActivityLine($ev, $processedAt), $expAct, "{$label} activity line");
        assert_eq(BookingFormatter::formatDigestLine($ev), 'CANCELLED: Brian Sperlongano, 3/6 (2 nights), 1 member', "{$label} digest line");
    }
    if ($file === 'Notification of tentative booking from 2 23 2026 to 2 23 2026.eml') {
        $expAct = $processedAt . ' | BOOKED | Brian Sperlongano | 2/23/2026 11:30 AM -> 2/24/2026 11:00 AM | 1 member, 1 guest';
        assert_eq(BookingFormatter::formatActivityLine($ev, $processedAt), $expAct, "{$label} activity line");
        assert_eq(BookingFormatter::formatDigestLine($ev), 'BOOKED: Brian Sperlongano, 2/23 (1 night), 1 member, 1 guest', "{$label} digest line");
    }
    if ($file === 'Booking NP008344 from 3 13 2026 to 3 15 2026 was edited, status TENTATIVE,.eml') {
        assert_eq(
            BookingFormatter::formatDigestLine($ev),
            'EDITED: Brian Sperlongano, -, 0 occupants parsed',
            "{$label} digest line"
        );
    }
}

// UNKNOWN without MIME envelope: dates + dashes; no occupancy tail when all counts zero.
$nakedUnknown = RawEmailEnvelope::enrichParsedEvent(BookingParser::parse('not a booking template at all'), null);
$nakedLine = BookingFormatter::formatActivityLine($nakedUnknown, $processedAt);
assert_eq(strpos($nakedLine, 'from=') === false, true, 'body-only UNKNOWN must not use from=/subject= line');
assert_eq(strpos($nakedLine, 'members=') === false, true, 'body-only UNKNOWN has no key=value counts');
assert_eq($nakedLine, $processedAt . ' | UNKNOWN | - | - -> -', 'body-only UNKNOWN ends at dates when counts all zero');

// UNKNOWN but From is club mailbox: not treated as external inbound.
$clubUnknown = BookingParser::parse('unrecognized club noise');
$clubUnknown = RawEmailEnvelope::enrichParsedEvent($clubUnknown, [
    'from' => 'Brian via CBDWeb <bookings@newportskiclub.org>',
    'to' => 'bookings@newportskiclub.org',
    'delivered_to' => 'bookings@newportskiclub.org',
    'subject' => 'stray',
    'return_path' => '<bookings@newportskiclub.org>',
]);
assert_eq($clubUnknown['unknown_inbound_external'] ?? false, false, 'club From blocks simplified UNKNOWN log');
$clubLine = BookingFormatter::formatActivityLine($clubUnknown, $processedAt);
assert_eq(strpos($clubLine, 'from=') === false, true, 'club UNKNOWN uses full activity line');
assert_eq($clubLine, $processedAt . ' | UNKNOWN | - | - -> -', 'club UNKNOWN no occupancy tail when zero');

echo "All tests passed.\n";
exit(0);
