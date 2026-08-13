<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\TermsConsent;

/**
 * What the billing audit trail is allowed to be about (FR-026 · FR-034).
 *
 * The mirror of `Settlement\Support\SettlementAuditSubjects` — named as TEXT and
 * never as a `{@see}`, because pint turns that into a `use` statement and
 * `ContextIsolationTest` fails the build over a Payments file importing anything
 * from Settlement. The guard is right to: an import is how a docblock reference
 * becomes a call. And the pairing is the requirement here: `activity_log` is ONE shared table that
 * both contexts write to. A reader that fetched the table and then dropped the
 * settlement rows would be one forgotten branch away from showing an auditor of
 * student payments what a teacher was paid — which is the disclosure the whole
 * separation exists to prevent.
 *
 * So this endpoint never asks for the table. It asks for these seven subject
 * types, and there is no branch in which it asks for more.
 *
 * ⚠️ THE LIST IS A CONTRACT, NOT AN INVENTORY. Three of the seven have a writer
 * today — `ApproveOrder`/`RejectOrder`/`UploadPaymentReceipt` on `Order`,
 * `ManageExamModeWindow` on `ExamModeWindow`, `SetCreditLimit` on
 * `CreditBalance`. The other four are declared here so that the first Action to
 * log against them is inside the trail rather than invisible to it. What that
 * costs is that an assertion naming them measures an empty table and passes: the
 * tests therefore assert only the types something actually writes, and each of
 * the remaining four gets its assertion in the change that gives it a writer.
 */
final class BillingAuditSubjects
{
    /**
     * Model class → the slug the payload names it by.
     *
     * A slug rather than the class name, because a class name on the wire is a
     * map of the codebase handed to whoever holds a token — and it changes under
     * a rename, which a stored audit entry must not.
     *
     * @var array<class-string, string>
     */
    public const MAP = [
        Order::class => 'order',
        PaymentTransaction::class => 'payment',
        CreditTransaction::class => 'credit_entry',
        CreditBalance::class => 'balance',
        CreditPackage::class => 'package',
        ExamModeWindow::class => 'exam_window',
        TermsConsent::class => 'consent',
    ];

    /** @return list<class-string> */
    public static function types(): array
    {
        return array_keys(self::MAP);
    }

    public static function slugFor(?string $class): ?string
    {
        return $class === null ? null : (self::MAP[$class] ?? null);
    }
}
