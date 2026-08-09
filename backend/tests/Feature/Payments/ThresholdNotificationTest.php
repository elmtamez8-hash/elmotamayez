<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Collection;

/*
| SC-009 · FR-030 … FR-034 — the ladder, and the silence between its rungs.
|
| Two claims, and the second is the one that is hard:
|
|   · a quiet word to the STUDENT at the first threshold, and the person who pays
|     only at the second — an escalation, not a broadcast;
|   · the SAME crossing is never announced twice, and a recomputation announces
|     nothing at all.
|
| The de-duplication has no table behind it. The rank is claimed with a
| conditional UPDATE inside the transaction that moved the balance, so two
| workers charging the same balance compute the same rank and exactly one of
| them affects a row. That is why the third case below — moving the balance again
| without crossing anything — must produce zero, and why the fourth — up and
| back down — must produce exactly one.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BillingSettings::class)->save($this->workspace, ['alert_thresholds' => [3, 1]]);

    $this->course = billingCourse($this->workspace);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->balance = billingBalance($this->workspace, $this->student, $this->course);

    grantCredits($this->balance, 6, 'ladder');
});

/** Moves the balance through the Action, which is what announces. */
function moveLadderBalance(CreditBalance $balance, int $credits, string $key): void
{
    app(AdjustCredits::class)->handle(
        $balance,
        $credits < 0 ? CreditTransactionType::Adjustment : CreditTransactionType::Bonus,
        $credits,
        'اختبار العتبات',
        $key,
    );

    $balance->refresh();
}

/** @return Collection<int, Notification> */
function ladderNotifications(NotificationType $type)
{
    return Notification::query()->where('type', $type->value)->get();
}

it('tells the student alone at the first threshold', function (): void {
    // 6 → 3, which is the first threshold exactly. "At" counts: a student with
    // three left is the person the first nudge was written for.
    moveLadderBalance($this->balance, -3, 'first');

    expect(ladderNotifications(NotificationType::CreditBalanceLow))->toHaveCount(1)
        ->and(ladderNotifications(NotificationType::CreditBalanceLow)->first()->recipient_user_id)
        ->toBe($this->student->getKey())
        ->and(ladderNotifications(NotificationType::CreditBalanceCritical))->toHaveCount(0);
});

it('reaches the guardian at the second, and the student too', function (): void {
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $this->student->getKey(),
        'permissions' => [GuardianPermission::Payments->value],
    ]);

    moveLadderBalance($this->balance, -5, 'second');

    $critical = ladderNotifications(NotificationType::CreditBalanceCritical);

    // Two records for one event: the feed is per person, and a guardian of three
    // children needs their own row to mark read.
    expect($critical->pluck('recipient_user_id')->sort()->values()->all())
        ->toBe(collect([$this->student->getKey(), $guardian->getKey()])->sort()->values()->all());
});

it('says nothing when the balance moves without crossing anything', function (): void {
    moveLadderBalance($this->balance, -3, 'first');

    expect(ladderNotifications(NotificationType::CreditBalanceLow))->toHaveCount(1);

    // Still inside the same rank. FR-034 — a recomputation is not a new event,
    // and without the claim being made inside the movement's transaction this is
    // where a second alert would appear.
    moveLadderBalance($this->balance, -1, 'again');

    expect(ladderNotifications(NotificationType::CreditBalanceLow))->toHaveCount(1);
});

it('announces the same depth again only after the balance recovered', function (): void {
    moveLadderBalance($this->balance, -3, 'down-one');

    expect(ladderNotifications(NotificationType::CreditBalanceLow))->toHaveCount(1);

    // Up, clear of every threshold. The rank resets on the way up and announces
    // nothing — nobody needs telling they are better off.
    moveLadderBalance($this->balance, 5, 'up');

    expect(Notification::query()->whereIn('type', [
        NotificationType::CreditBalanceLow->value,
        NotificationType::CreditBalanceCritical->value,
    ])->count())->toBe(1);

    // And down to the same depth: a NEW fall, so it is heard again.
    moveLadderBalance($this->balance, -5, 'down-two');

    expect(ladderNotifications(NotificationType::CreditBalanceLow))->toHaveCount(2);
});
