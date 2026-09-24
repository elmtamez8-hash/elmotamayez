<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Shared\Support\GuardianPermission;

/*
| `access_withheld` and `access_restored` from a real balance movement — the
| ledger's own Action, then `BalanceAnnouncer`, then `NotifyAccessChange`
| (`EveryNotificationTypeIsTestedTest`).
|
| ⚠️ The earlier references to these types were either an HTTP refusal CODE of
| the same spelling (`'access_withheld'` is also what a refused playback answers)
| or a dispatcher fixture; neither says whether being blocked ever tells anyone.
| Both are MANDATORY types and both reach the guardian entitled to payments — the
| person who can actually fix it.
|
| The student is self-registered (a member of no workspace, null context): the
| listener reads the course with `withoutWorkspaceScope()` precisely because the
| 024 defect made this read return null and the notification vanish.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();

    // A deferring workspace, so a balance has somewhere to fall to and the
    // withholding predicate has something to say (the RefundFloorTest fixture).
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $this->balance = billingBalance($this->workspace, $this->student, $this->course);

    grantCredits($this->balance, 2, 'access-fixture');
    $this->balance->refresh();

    $this->payer = guardianOf($this->student, [GuardianPermission::Payments]);
    $this->attendanceOnly = guardianOf($this->student, [GuardianPermission::Attendance]);
});

it('tells the student and the paying guardian that booking stopped, then that it resumed', function (): void {
    app(AdjustCredits::class)->handle(
        balance: $this->balance,
        type: CreditTransactionType::Adjustment,
        credits: -2,
        reason: 'تصحيح رصيد',
        idempotencyKey: 'access-drain',
    );

    assertNotifiedOnce($this->student, NotificationType::AccessWithheld);
    assertNotifiedOnce($this->payer, NotificationType::AccessWithheld);

    expect(wasNotified($this->attendanceOnly, NotificationType::AccessWithheld))->toBeFalse()
        ->and(wasNotified($this->student, NotificationType::AccessRestored))->toBeFalse();

    app(AdjustCredits::class)->handle(
        balance: $this->balance->refresh(),
        type: CreditTransactionType::Bonus,
        credits: 3,
        reason: 'مكافأة',
        idempotencyKey: 'access-refill',
    );

    assertNotifiedOnce($this->student, NotificationType::AccessRestored);
    assertNotifiedOnce($this->payer, NotificationType::AccessRestored);

    expect(wasNotified($this->attendanceOnly, NotificationType::AccessRestored))->toBeFalse();
});
