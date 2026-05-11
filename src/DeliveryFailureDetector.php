<?php

declare(strict_types=1);

/**
 * Detects MTA bounce / delivery-failure messages so they are not parsed as bookings.
 * (Bounces often include prior pipe stdout; parsing them can append spurious log lines.)
 */
final class DeliveryFailureDetector
{
    /**
     * True when the raw RFC822 message is very likely a bounce or DSN, not a NORA notice.
     */
    public static function looksLikeAutomatedDeliveryFailure(string $raw): bool
    {
        if (stripos($raw, 'Content-Type: multipart/report') !== false
            && stripos($raw, 'report-type=delivery-status') !== false) {
            return true;
        }

        $probe = strtolower(substr($raw, 0, 16384));
        $markers = [
            'this message was created automatically by mail delivery software',
            'the following address(es) failed',
            'following text was generated during the delivery attempt',
            '------ pipe to |',
            'delivery status notification',
            'undelivered mail returned to sender',
            'returned mail: see transcript for details',
            'this is a permanent error',
        ];
        foreach ($markers as $m) {
            if (strpos($probe, $m) !== false) {
                return true;
            }
        }

        return false;
    }
}
