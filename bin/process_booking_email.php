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
 * @param ?string $explicitFromArgs null or path from --log
 */
function nsc_resolve_activity_log_path(?string $explicitFromArgs): ?string
{
    if ($explicitFromArgs !== null && $explicitFromArgs !== '') {
        return $explicitFromArgs;
    }
    $fromEnv = getenv('NSC_ACTIVITY_LOG');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    $piped = function_exists('posix_isatty') && defined('STDIN') && !posix_isatty(STDIN);
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

$logPath = nsc_resolve_activity_log_path($logPath);

$raw = '';
if (isset($positional[0]) && $positional[0] !== '-') {
    $path = $positional[0];
    if (!is_readable($path)) {
        fwrite(STDERR, "Cannot read file: {$path}\n");
        exit(1);
    }
    $raw = (string) file_get_contents($path);
} else {
    $raw = stream_get_contents(STDIN) ?: '';
}

$envelope = EmlBodyExtractor::extractEnvelope($raw);
$body = EmlBodyExtractor::extractBodyForParser($raw);
$event = RawEmailEnvelope::enrichParsedEvent(BookingParser::parse($body), $envelope);
$processedAt = gmdate('Y-m-d\TH:i:s\Z');
$activityLine = BookingFormatter::formatActivityLine($event, $processedAt);
$digestLine = BookingFormatter::formatDigestLine($event);

if ($logPath !== null && $logPath !== '') {
    if (!LogWriter::appendLine($logPath, $activityLine)) {
        fwrite(STDERR, "Failed to append activity line to log: {$logPath}\n");
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
