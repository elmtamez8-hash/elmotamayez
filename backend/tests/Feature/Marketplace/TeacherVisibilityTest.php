<?php

declare(strict_types=1);

use App\Modules\Marketplace\Actions\ReinstateTeacher;
use App\Modules\Marketplace\Actions\SetMarketplaceParticipation;
use App\Modules\Marketplace\Actions\SuspendTeacher;
use App\Modules\Marketplace\Models\TeacherProfile;

/**
 * Who the marketplace shows, and how fast it stops showing them.
 *
 * SC-010 gives a status change one minute to reach the public listing. The
 * listing cache is the only thing between the two, so every Action that changes
 * visibility flushes it — these tests read the list immediately after and expect
 * the new answer, not the cached one.
 */
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace();
});

it('hides teachers who are not approved', function (string $status): void {
    marketplaceTeacher($this->workspace, [
        'approval_status' => $status,
        // The flag alone is not enough: publiclyListed() requires approval too, so
        // a stale true here must still not publish anyone.
        'is_publicly_listed' => true,
    ]);

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0);
})->with([
    TeacherProfile::STATUS_PENDING,
    TeacherProfile::STATUS_REJECTED,
    TeacherProfile::STATUS_SUSPENDED,
]);

it('shows an approved teacher immediately after suspension is lifted', function (): void {
    $teacher = marketplaceTeacher($this->workspace);

    $this->asGuest();
    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);

    app(SuspendTeacher::class)->handle($teacher);

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0)
        ->and($this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")->status())->toBe(404);

    app(ReinstateTeacher::class)->handle($teacher);

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);
});

it('reinstates without listing when the workspace has left the marketplace', function (): void {
    $teacher = marketplaceTeacher($this->workspace);

    app(SuspendTeacher::class)->handle($teacher);
    app(SetMarketplaceParticipation::class)->handle($this->workspace, false);
    app(ReinstateTeacher::class)->handle($teacher);

    $this->asGuest();

    expect($teacher->fresh()?->approval_status)->toBe(TeacherProfile::STATUS_APPROVED)
        ->and($teacher->fresh()?->is_publicly_listed)->toBeFalse()
        ->and($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0);
});

// Withdrawal is the academy's decision about where its listings appear, not a
// verdict on its teachers — so nobody's approval_status may change.
it('hides a whole workspace on withdrawal without touching approval status', function (): void {
    $teacher = marketplaceTeacher($this->workspace);

    $this->asGuest();
    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);

    app(SetMarketplaceParticipation::class)->handle($this->workspace, false);

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0)
        ->and($teacher->fresh()?->approval_status)->toBe(TeacherProfile::STATUS_APPROVED)
        ->and($teacher->fresh()?->is_publicly_listed)->toBeTrue();

    // Re-joining restores the listings without a second round of approvals.
    app(SetMarketplaceParticipation::class)->handle($this->workspace, true);

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);
});
