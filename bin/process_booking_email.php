#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Bluehost/cPanel “pipe to program”: path is relative to home, with no `php` prefix—only this
 * executable script. Shebang must point at your server’s PHP (see README if MultiPHP uses another path).
 */

$root = dirname(__DIR__);

/** @return string|null */
function nsc_resolve_home_dir(): ?string
{
    $h = getenv('HOME');
    if ($h !== false && $h !== '') {
        return $h;
    }
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['dir'])) {
            return $pw['dir'];
        }
    }

    return null;
}

/**
 * Mail pipes on some hosts run PHP without the CLI constants STDIN/STDERR; use php://stdin instead.
 *
 * @return resource|false|null null = could not open
 */
function nsc_open_stdin()
{
    if (defined('STDIN')) {
        return STDIN;
    }

    return @fopen('php://stdin', 'rb');
}

function nsc_stdin_is_terminal($stdinHandle): bool
{
    if ($stdinHandle === false || $stdinHandle === null) {
        return false;
    }
    if (function_exists('stream_isatty')) {
        return stream_isatty($stdinHandle);
    }
    if (function_exists('posix_isatty')) {
        return posix_isatty($stdinHandle);
    }

    return false;
}

function nsc_fwrite_stderr(string $msg): void
{
    if (defined('STDERR')) {
        fwrite(STDERR, $msg);
    } else {
        @file_put_contents('php://stderr', $msg);
    }
}

/**
 * @param ?string $explicitFromArgs null or path from --log
 * @param resource|false|null $stdinHandle
 */
function nsc_resolve_activity_log_path(?string $explicitFromArgs, $stdinHandle): ?string
{
    if ($explicitFromArgs !== null && $explicitFromArgs !== '') {
        return $explicitFromArgs;
    }
    $fromEnv = getenv('NSC_ACTIVITY_LOG');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    // Piped mail: stdin is not a terminal. If we cannot tell, treat as non-TTY so logging still works.
    $piped = !nsc_stdin_is_terminal($stdinHandle);
    if (!$piped) {
        return null;
    }
    $home = nsc_resolve_home_dir();
    if ($home === null) {
        return null;
    }

    return $home . '/logs/booking_activity.log';
}

require_once $root . '/src/EmlBodyExtractor.php';
require_once $root . '/src/RawEmailEnvelope.php';
require_once $root . '/src/EmailNormalizer.php';
require_once $root . '/src/BookingParser.php';
require_once $root . '/src/BookingFormatter.php';
require_once $root . '/src/LogWriter.php';
require_once $root . '/src/LogTimestamp.php';

$args = array_slice($argv, 1);
$logPath = null;
$jsonOnly = false;
$prettyJson = false;
$positional = [];

for ($i = 0, $n = count($args); $i < $n; $i++) {
    $a = $args[$i];
    if ($a === '--json') {
        $jsonOnly = true;
    } elseif ($a === '--pretty') {
        $prettyJson = true;
    } elseif ($a === '--log') {
        $logPath = $args[++$i] ?? null;
    } elseif (strncmp($a, '--log=', 6) === 0) {
        $logPath = substr($a, 6);
    } else {
        $positional[] = $a;
    }
}

$stdinHandle = nsc_open_stdin();
$logPath = nsc_resolve_activity_log_path($logPath, $stdinHandle);

$raw = '';
if (isset($positional[0]) && $positional[0] !== '-') {
    $path = $positional[0];
    if (!is_readable($path)) {
        nsc_fwrite_stderr("Cannot read file: {$path}\n");
        exit(1);
    }
    $raw = (string) file_get_contents($path);
} else {
    if ($stdinHandle !== false && $stdinHandle !== null) {
        $raw = stream_get_contents($stdinHandle) ?: '';
    } else {
        $raw = '';
    }
}

$envelope = EmlBodyExtractor::extractEnvelope($raw);
$body = EmlBodyExtractor::extractBodyForParser($raw);
$event = RawEmailEnvelope::enrichParsedEvent(BookingParser::parse($body), $envelope);
$processedAt = LogTimestamp::now();
$activityLine = BookingFormatter::formatActivityLine($event, $processedAt);
$digestLine = BookingFormatter::formatDigestLine($event);

if ($logPath !== null && $logPath !== '') {
    if (!LogWriter::appendLine($logPath, $activityLine)) {
        nsc_fwrite_stderr("Failed to append activity line to log: {$logPath}\n");
        exit(1);
    }
}

if ($jsonOnly) {
    $payload = [
        'processed_at' => $processedAt,
        'event' => $event,
        'activity_line' => $activityLine,
        'digest_line' => $digestLine,
    ];
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($prettyJson) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($payload, $flags) . "\n";
    exit(0);
}

echo "Digest:\n{$digestLine}\n\n";
echo "Activity:\n{$activityLine}\n\n";
echo "Structured (JSON):\n";
echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
