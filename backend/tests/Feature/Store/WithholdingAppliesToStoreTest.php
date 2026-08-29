<?php

declare(strict_types=1);

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\IssueStoreAccess;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AccountStanding;

/*
| FR-042 reached from a new door: a student who owes on a course does not open a
| file sold against that course either.
|
| ⚠️ AND WITHHOLDING IS PER COURSE, WHICH DECIDES THE SECOND CASE. A book that
| hangs off no course has no balance to owe against — inventing one would
| withhold a purchase over a debt on the other side of the platform, and there is
| no correct total to compare it with because balances are never summed.
|
| ⚠️ THE CONTRACT IS `AccountStanding`, NEVER `Payments\Support\WithholdingReader`.
| The first is the sanctioned cross-module contract; the second is another
| module's class and `ContextIsolationTest` fails the build over the import.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // A deferring mode with no ceiling behind it: without this the balance is
    // never withheld and every assertion below passes for the wrong reason.
    app(BillingSettings::class)->save($this->workspace, ['mode' => BillingMode::ManualCollection->value]);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course)->refresh();

    $this->asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->session = AuthSession::factory()->create(['user_id' => $this->student->getKey()]);
});

function boughtAgainstCourse(?int $courseId): string
{
    $item = StoreItem::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'media_asset_id' => test()->asset->getKey(),
        'course_id' => $courseId,
    ]);

    $purchase = app(PurchaseStoreItem::class)->handle(test()->student, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
    ]));

    app(FulfilStorePurchase::class)->handle(
        Order::query()->whereKey($purchase->order_id)->firstOrFail(),
    );

    return $purchase->uuid;
}

it('opens a purchase for a student who owes nothing', function (): void {
    // Funded, because a zero balance in a deferring mode IS withheld — the
    // student has nothing to pay the next session with. A positive control has
    // to neutralise the condition it is not measuring, or it measures whichever
    // fires first (spec 010's US6, nine cases, all green with the check deleted).
    grantCredits($this->balance, 2, 'fixture-funding');

    expect(app(AccountStanding::class)->isWithheld($this->student, (int) $this->course->getKey()))->toBeFalse();

    $grant = app(IssueStoreAccess::class)
        ->handle(boughtAgainstCourse((int) $this->course->getKey()), $this->student, $this->session);

    expect($grant->media_asset_id)->toBe($this->asset->getKey());
});

it('refuses a book sold against a course the student owes on', function (): void {
    $purchaseUuid = boughtAgainstCourse((int) $this->course->getKey());

    // Owing: the balance goes negative with no ceiling under it.
    $this->balance->forceFill(['remaining_credits' => -2, 'credit_limit_credits' => 0])->save();

    expect(app(AccountStanding::class)->isWithheld($this->student, (int) $this->course->getKey()))->toBeTrue();

    expect(fn () => app(IssueStoreAccess::class)
        ->handle($purchaseUuid, $this->student, $this->session))
        ->toThrow(RuntimeException::class);
});

it('opens a standalone book while the same student owes on a course', function (): void {
    $purchaseUuid = boughtAgainstCourse(null);

    $this->balance->forceFill(['remaining_credits' => -2, 'credit_limit_credits' => 0])->save();

    // ⚠️ THE PER-COURSE RULE. The student owes, and this book belongs to no
    // course — so there is nothing it can be withheld against. Summing balances
    // to answer «does this person owe» is the defect this case exists to refuse:
    // +10 in maths and −6 in physics reads as +4 and unblocked.
    $grant = app(IssueStoreAccess::class)->handle($purchaseUuid, $this->student, $this->session);

    expect($grant->media_asset_id)->toBe($this->asset->getKey());
});
