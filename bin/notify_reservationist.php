#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reads the full activity log, emails it as plain text to the reservationist,
 * then archives the log with a UTC timestamp and recreates an empty log file.
 * On mail() failure: does not roll over; exits non-zero.
 */

function nsc_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

$logPath = nsc_env('NSC_ACTIVITY_LOG');
if ($logPath === '') {
    fwrite(STDERR, "NSC_ACTIVITY_LOG is not set.\n");
    exit(1);
}

if (!is_file($logPath) || !is_readable($logPath)) {
    exit(0);
}

$content = (string) file_get_contents($logPath);
if (trim($content) === '') {
    exit(0);
}

$to = nsc_env('NSC_MAIL_TO');
if ($to === '') {
    fwrite(STDERR, "NSC_MAIL_TO is not set.\n");
    exit(1);
}

$from = nsc_env('NSC_MAIL_FROM', 'bookings@newportskiclub.org');
$subject = nsc_env('NSC_MAIL_SUBJECT', 'New Newport Ski Club booking activity');

$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'From: ' . $from;

$headerStr = implode("\r\n", $headers);
$ok = @mail($to, $subject, $content, $headerStr);
if ($ok !== true) {
    fwrite(STDERR, "mail() reported failure; log not rolled over.\n");
    exit(1);
}

$dir = dirname($logPath);
$base = basename($logPath);
$name = pathinfo($base, PATHINFO_FILENAME);
$ext = pathinfo($base, PATHINFO_EXTENSION);
$suffix = $ext !== '' ? '.' . $ext : '';
$ts = gmdate('Ymd\THis\Z');
$archiveName = $name . '_' . $ts . $suffix;
$archivePath = $dir . '/' . $archiveName;

if (!@rename($logPath, $archivePath)) {
    fwrite(STDERR, "Sent mail but failed to rename log to archive: {$archivePath}\n");
    exit(1);
}

if (@file_put_contents($logPath, '') === false) {
    fwrite(STDERR, "Archived log but failed to recreate empty log at: {$logPath}\n");
    exit(1);
}

fwrite(STDERR, "Sent activity notification and rolled log to {$archiveName}\n");
exit(0);
