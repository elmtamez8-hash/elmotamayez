<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Payments\Support\SiblingDiscount;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Facades\DB;

/*
| SC-004 — the family discount, automatic, with no code and no request (T074).
|
| ⚠️ DISCOVERY IS THE PROVEN RELATION, NEVER THE PHONE NUMBER the spec first
| assumed. `users.phone` is a free string nobody confirmed, and this repository
| has already written down what a typo in it costs — «a message about a child
| sent to a stranger». Here it would hand one family another family's money, and
| the spec's own edge case (two children linked to different guardians on one
| number) disappears rather than needing a rule.
|
| ⚠️ AND THE FIXTURE MUST SET THE PLATFORM VALUE, because the default is ZERO.
| A file that forgot to would find every assertion about «no discount» passing —
| against a feature that was switched off rather than a family that did not
| qualify.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 10_000,
    ]);

    $this->guardian = User::factory()->create();
    $this->child = User::factory()->create();
    $this->onlyChild = User::factory()->create();

    PlatformSettings::set('billing.sibling_discount', 10);
});

afterEach(function (): void {
    PlatformSettings::flush();
});

function discountForBuyer(User $buyer): int
{
    return (int) app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => test()->item->uuid,
    ]))->discount_minor;
}

function relate(User $guardian, ?User $student, ?string $name = null): ParentStudentRelation
{
    return ParentStudentRelation::factory()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student?->getKey(),
        'student_name' => $name ?? 'طالب',
    ]);
}

it('discounts every sibling once a second child is registered', function (): void {
    $second = User::factory()->create();

    relate($this->guardian, $this->child);
    relate($this->guardian, $second);

    // Both, not only whoever signed up second. «The second child onwards»
    // describes a FAMILY; telling the elder child they missed the family rate by
    // being born first is not what FR-013 asks for.
    expect(discountForBuyer($this->child))->toBe(1_000)
        ->and(discountForBuyer($second))->toBe(1_000);
});

it('gives an only child nothing', function (): void {
    relate($this->guardian, $this->onlyChild);

    expect(discountForBuyer($this->onlyChild))->toBe(0);
});

it('gives a student with no guardian at all nothing', function (): void {
    expect(discountForBuyer(User::factory()->create()))->toBe(0);
});

it('does not count a sibling who has no account yet', function (): void {
    /*
    | The relation carries `student_name` with no `student_user_id` until that
    | child signs up. A discount for an account that does not exist is a discount
    | nobody can ever check — and `childrenOf()` already excludes them for the
    | same reason.
    */
    relate($this->guardian, $this->onlyChild);
    relate($this->guardian, null, 'أخٌ لم يسجّلْ بعد');

    expect(discountForBuyer($this->onlyChild))->toBe(0);
});

it('does not count a sibling whose relation was revoked', function (): void {
    relate($this->guardian, $this->child);

    ParentStudentRelation::factory()->revoked()->create([
        'guardian_user_id' => $this->guardian->getKey(),
        'student_user_id' => User::factory()->create()->getKey(),
    ]);

    expect(discountForBuyer($this->child))->toBe(0);
});

it('does not count a sibling reached through a revoked relation of the buyer', function (): void {
    // The mirror of the case above: the SIBLING is fine and the buyer's own
    // relation is dead. Both halves of the join must be active or a departed
    // guardian keeps discounting a family they no longer belong to.
    $sibling = User::factory()->create();

    ParentStudentRelation::factory()->revoked()->create([
        'guardian_user_id' => $this->guardian->getKey(),
        'student_user_id' => $this->child->getKey(),
    ]);

    relate($this->guardian, $sibling);

    expect(discountForBuyer($this->child))->toBe(0);
});

it('is off when the platform value is zero', function (): void {
    relate($this->guardian, $this->child);
    relate($this->guardian, User::factory()->create());

    PlatformSettings::set('billing.sibling_discount', 0);

    expect(discountForBuyer($this->child))->toBe(0);
});

it('never asks the directory when the discount is switched off', function (): void {
    // Asked BEFORE the relation lookup, deliberately: this runs on every purchase
    // on the platform, and a platform that has not turned the feature on should
    // pay for no query at all.
    relate($this->guardian, $this->child);
    relate($this->guardian, User::factory()->create());

    PlatformSettings::set('billing.sibling_discount', 0);

    $touched = [];

    DB::listen(function ($query) use (&$touched): void {
        if (str_contains($query->sql, 'parent_student_relations')) {
            $touched[] = $query->sql;
        }
    });

    expect(app(SiblingDiscount::class)->percentFor($this->child))->toBe(0)
        // The setting itself is still read — it is one cached row and it is what
        // answers the question. What must not happen is the family lookup.
        ->and($touched)->toBe([]);
});

it('ignores the permission a guardian happens to hold', function (): void {
    /*
    | «Is this a second child» is a fact about a family, not something a guardian
    | is authorised for. Keyed on a `GuardianPermission`, a family discount would
    | depend on whether a parent ticked «attendance» — and a permission revoked
    | afterwards would silently reprice the next purchase.
    */
    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Attendance])
        ->create([
            'guardian_user_id' => $this->guardian->getKey(),
            'student_user_id' => $this->child->getKey(),
        ]);

    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Attendance])
        ->create([
            'guardian_user_id' => $this->guardian->getKey(),
            'student_user_id' => User::factory()->create()->getKey(),
        ]);

    expect(discountForBuyer($this->child))->toBe(1_000);
});

it('clamps a nonsense platform value at both ends', function (): void {
    relate($this->guardian, $this->child);
    relate($this->guardian, User::factory()->create());

    PlatformSettings::set('billing.sibling_discount', 500);
    expect(app(SiblingDiscount::class)->percentFor($this->child))->toBe(100);

    PlatformSettings::set('billing.sibling_discount', -20);
    expect(app(SiblingDiscount::class)->percentFor($this->child))->toBe(0);
});
