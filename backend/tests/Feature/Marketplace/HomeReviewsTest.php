<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;

/**
 * The home page may only quote people who exist.
 *
 * ⚠️ THIS FILE EXISTS BECAUSE THE OPPOSITE SHIPPED. `GetMarketplaceHome` used to
 * return three quotes written into the Action itself, attributed to invented
 * people carrying real Qatari family names — نورة العلي, عبدالله المري,
 * مريم الكواري — served to every visitor with nothing marking them as samples.
 *
 * PRODUCT.md's `Evidence on Hand` bans it in one line: the product is pre-launch
 * with no customers, so any quote on a surface is labelled demo data or does not
 * appear. Nothing in the suite noticed for the life of the feature, because a
 * hardcoded array passes every test that only asserts the key is present.
 *
 * The invariant here is the one that catches the relapse: with no reviews in the
 * database the section is EMPTY. An authored quote cannot satisfy that, whatever
 * shape it wears — and "the home page looks bare before launch" is exactly the
 * pressure under which someone writes one back in.
 */
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace('Academy');
    MarketplaceCache::flush();
});

/** Create a visible review for a teacher, inside that teacher's workspace. */
function homeReview(
    TeacherProfile $teacher,
    Workspace $workspace,
    string $comment,
    int $rating = 5,
    bool $visible = true,
): Review {
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Review => Review::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $teacher->getKey(),
            'rating' => $rating,
            'comment' => $comment,
            'is_visible' => $visible,
        ]),
    );
}

it('publishes nothing when no student has written a review', function (): void {
    marketplaceTeacher($this->workspace);

    $this->asGuest();

    $payload = $this->getJson('/api/v1/marketplace/home')->json('testimonials');

    // The whole point. A hardcoded quote fails right here.
    expect($payload)->toBe([]);
});

it('publishes a review a student actually wrote', function (): void {
    $teacher = marketplaceTeacher($this->workspace);
    homeReview($teacher, $this->workspace, 'شرح ممتاز وصبور.');

    $this->asGuest();

    $payload = $this->getJson('/api/v1/marketplace/home')->json('testimonials');

    expect($payload)->toHaveCount(1);
    expect($payload[0]['comment'])->toBe('شرح ممتاز وصبور.');
    expect($payload[0]['rating'])->toBe(5);
});

it('truncates the reviewer to a display name', function (): void {
    $teacher = marketplaceTeacher($this->workspace);
    $review = homeReview($teacher, $this->workspace, 'ممتاز.');

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($review): void {
        $review->student?->forceFill(['first_name' => 'نورة', 'last_name' => 'العلي'])->save();
    });

    MarketplaceCache::flush();
    $this->asGuest();

    $name = $this->getJson('/api/v1/marketplace/home')->json('testimonials.0.student_display_name');

    // "نورة م." not "نورة العلي" — enough to show a real person wrote it, not
    // enough to identify them to the teacher they just rated (FR-021).
    expect($name)->not->toContain('العلي');
    expect($name)->toContain('نورة');
});

it('hides a moderated review', function (): void {
    $teacher = marketplaceTeacher($this->workspace);
    homeReview($teacher, $this->workspace, 'تعليق مخفيّ.', visible: false);

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/home')->json('testimonials'))->toBe([]);
});

/*
| ⚠️ The isolation half, and the reason it is not redundant with the tenancy
| suite: Review carries BelongsToWorkspace, and WorkspaceScope adds NO condition
| on a guest request. So the trait that isolates this model everywhere else is
| inert on exactly the route that publishes it to the world. publiclyListed() is
| the only thing standing between a withdrawn workspace and the front page.
*/
it('never quotes a teacher whose workspace left the marketplace', function (): void {
    $teacher = marketplaceTeacher($this->workspace);
    homeReview($teacher, $this->workspace, 'تعليق لمدرّس منسحب.');

    $this->asGuest();
    expect($this->getJson('/api/v1/marketplace/home')->json('testimonials'))->toHaveCount(1);

    $this->workspace->forceFill(['participates_in_marketplace' => false])->save();
    MarketplaceCache::flush();

    expect($this->getJson('/api/v1/marketplace/home')->json('testimonials'))->toBe([]);
});
