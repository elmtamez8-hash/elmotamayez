<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How a workspace collects (FR-010). Stored in `workspaces.settings.billing.mode`.
 *
 * Read it through BillingSettings and nowhere else (FR-013). A mode compared
 * inline in a second file is a second decision that drifts from the first, and
 * SingleSourceOfModeTest fails the build over any literal outside that class.
 */
enum BillingMode: string
{
    /** The launch default. Never goes below zero, whatever the credit limit says (FR-014). */
    case PrepaidCredits = 'prepaid_credits';

    case ManualCollection = 'manual_collection';

    case PaymentGateway = 'payment_gateway';

    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::PrepaidCredits => 'دفع مسبق بالأرصدة',
            self::ManualCollection => 'تحصيل يدوي',
            self::PaymentGateway => 'بوابة دفع',
            self::Hybrid => 'مزيج',
        };
    }

    /** Whether this mode lets a balance go negative at all. */
    public function allowsDeferral(): bool
    {
        return match ($this) {
            self::PrepaidCredits => false,
            self::ManualCollection, self::PaymentGateway, self::Hybrid => true,
        };
    }

    /**
     * Whether the platform can actually run this mode today (FR-015).
     *
     * ⚠️ EVERY MODE IS READY SINCE 007, and the exception was REMOVED rather
     * than inverted. `return $this !== self::PaymentGateway` inverted naively
     * becomes `$this === self::PaymentGateway`, which makes the method answer
     * false for the three modes that have worked all along — a two-character
     * edit that switches off manual collection on every workspace.
     *
     * The method itself stays. A future mode that names infrastructure nobody
     * has built yet is exactly what it exists to refuse, and deleting it would
     * mean rediscovering the need the next time.
     */
    public function isReady(): bool
    {
        return true;
    }
}
