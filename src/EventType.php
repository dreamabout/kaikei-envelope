<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope;

/**
 * The five event types the kaikei v1 envelope contract carries.
 *
 * Backed by their wire-format string -- match exactly what
 * Dreamshop's `KaikeiPayloadAssembler` produces and what Kaikei's
 * `PayloadValidator` accepts today. Do NOT rename a case's backing
 * string without a MAJOR version bump (it would invalidate every
 * envelope currently in flight + every audit row in
 * `kaikei_delivery_log`).
 */
enum EventType: string
{
    case OrderShipped   = 'order.shipped';
    case OrderCaptured  = 'order.captured';
    case OrderRefunded  = 'order.refunded';
    case PayoutPaid     = 'payout.paid';
    case PaymentPrepaid = 'payment.prepaid';

    /**
     * Tolerant lookup -- returns null on unknown input rather than
     * throwing. The receiver uses this to short-circuit envelope
     * validation with `unknown_event_type` errors instead of an
     * uncaught \ValueError.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
