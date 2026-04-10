<?php

declare(strict_types=1);

/**
 * "When we processed this message" stamp for activity lines and JSON (US Eastern, 12-hour clock).
 */
final class LogTimestamp
{
    private const TZ = 'America/New_York';

    /** mm/dd/yy h:mm AM/PM in New York (handles EST/EDT). */
    public static function now(): string
    {
        $tz = new DateTimeZone(self::TZ);

        return (new DateTimeImmutable('now', $tz))->format('m/d/y g:i A');
    }
}
