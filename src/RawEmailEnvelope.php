<?php

declare(strict_types=1);

/**
 * Classifies inbound mail to the club booking inbox and merges envelope metadata into parsed events.
 */
final class RawEmailEnvelope
{
    private const BOOKINGS_MAILBOX = 'bookings@newportskiclub.org';

    /**
     * True when the message is addressed to the bookings mailbox and the visible sender is not that mailbox
     * (typical member/guest mail). Club-originated notifications use From and/or Return-Path @bookings@.
     *
     * @param array{from?: string, to?: string, delivered_to?: string, return_path?: string} $envelope
     */
    public static function isExternalInboundToBookings(array $envelope): bool
    {
        $to = $envelope['to'] ?? '';
        $delivered = $envelope['delivered_to'] ?? '';
        if (!self::fieldContainsBookingsMailbox($to) && !self::fieldContainsBookingsMailbox($delivered)) {
            return false;
        }
        if (self::senderIsBookingsMailbox($envelope['from'] ?? '', $envelope['return_path'] ?? '')) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $event
     * @param array{from: string, to: string, delivered_to: string, subject: string, return_path: string}|null $envelope
     * @return array<string, mixed>
     */
    public static function enrichParsedEvent(array $event, ?array $envelope): array
    {
        if ($envelope === null) {
            return $event;
        }
        $event['envelope_from'] = $envelope['from'];
        $event['envelope_to'] = $envelope['to'];
        $event['envelope_delivered_to'] = $envelope['delivered_to'];
        $event['envelope_subject'] = $envelope['subject'];
        $event['envelope_return_path'] = $envelope['return_path'];
        if (($event['event_type'] ?? '') === 'UNKNOWN') {
            $event['unknown_inbound_external'] = self::isExternalInboundToBookings($envelope);
        }

        return $event;
    }

    private static function fieldContainsBookingsMailbox(string $field): bool
    {
        return stripos($field, self::BOOKINGS_MAILBOX) !== false;
    }

    private static function senderIsBookingsMailbox(string $from, string $returnPath): bool
    {
        $fromAddr = self::firstMailbox($from);
        if ($fromAddr === self::BOOKINGS_MAILBOX) {
            return true;
        }
        $rpAddr = self::firstMailbox($returnPath);
        if ($fromAddr === null && $rpAddr === self::BOOKINGS_MAILBOX) {
            return true;
        }

        return false;
    }

    private static function firstMailbox(string $headerValue): ?string
    {
        $headerValue = trim($headerValue);
        if ($headerValue === '') {
            return null;
        }
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $headerValue, $m) === 1) {
            return strtolower(trim($m[1]));
        }
        if (preg_match('/\b([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', $headerValue, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }
}
