<?php

declare(strict_types=1);

/**
 * Append-only UTF-8 log line writer for activity entries.
 */
final class LogWriter
{
    public static function appendLine(string $logPath, string $line): bool
    {
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }
        $line = str_replace(["\r", "\n"], ' ', $line);
        $bytes = $line . "\n";
        $ok = @file_put_contents($logPath, $bytes, FILE_APPEND | LOCK_EX);
        return $ok !== false;
    }
}
