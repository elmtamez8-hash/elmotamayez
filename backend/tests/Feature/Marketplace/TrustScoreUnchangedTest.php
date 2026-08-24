<?php

declare(strict_types=1);

use App\Modules\Marketplace\Actions\RecalculateTrustScore;
use App\Modules\Marketplace\Models\Review;

/*
| `SC-011` — the three axes FR-031 adds feed the teacher's profile and change the
| trust score by NOTHING.
|
| ⚠️ THE FAILURE THIS GUARDS AGAINST IS SILENT AND PLATFORM-WIDE. `average_rating`
| is a materialised column that `TrustScoreCalculator::ratingFactor()` reads, and
| every review written before this phase has three empty axes. Fold them into the
| average without a null check — or let the migration write `0` into a `NOT NULL`
| column, which is exactly what MySQL does when SQLite refuses — and every teacher
| on the platform loses a third of their score overnight, with nothing logged and
| no request that failed.
*/

beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace();
    $this->teacher = marketplaceTeacher($this->workspace);
});

/**
 * Four reviews written the way spec 001 wrote them: a rating and no axes at all.
 * This is the shape of every row in the production database on deploy day.
 */
function preSpecTenReviews(int ...$ratings): void
{
    foreach ($ratings as $rating) {
        Review::factory()->create([
            'workspace_id' => test()->workspace->getKey(),
            'teacher_profile_id' => test()->teacher->getKey(),
            'rating' => $rating,
        ]);
    }
}

it('averages the ratings of rows whose axes are empty, and never reads a null as zero', function (): void {
    preSpecTenReviews(5, 4, 4, 3);

    app(RecalculateTrustScore::class)->handle($this->teacher);

    $teacher = $this->teacher->fresh();

    expect((float) $teacher?->average_rating)->toBe(4.0)
        ->and($teacher?->reviews_count)->toBe(4);

    // The axes really are null — a fixture that quietly filled them would make the
    // assertion above true about a case that cannot happen.
    expect(Review::query()->withoutWorkspaceScope()->whereNotNull('punctuality')->count())->toBe(0);
});

/*
| ⚠️ THE MEASUREMENT IS BEFORE-AND-AFTER ON THE SAME ROWS. Writing the axes at
| their MINIMUM while leaving `rating` alone is the sharpest form of the question:
| if any of them ever reaches the average — or the score — the number moves, and
| this fails. Asserting the score is "reasonable" would pass against an
| implementation that had folded them in.
*/
it('leaves the trust score identical when the three axes are written', function (): void {
    preSpecTenReviews(5, 4, 4, 3);

    app(RecalculateTrustScore::class)->handle($this->teacher);

    $before = $this->teacher->fresh();
    $scoreBefore = $before?->trust_score;
    $averageBefore = $before?->average_rating;

    // The floor of the range, on every row, without touching `rating`.
    Review::query()->withoutWorkspaceScope()->update([
        'punctuality' => 1,
        'clarity' => 1,
        'engagement' => 1,
    ]);

    app(RecalculateTrustScore::class)->handle($this->teacher->fresh());

    $after = $this->teacher->fresh();

    expect($after?->trust_score)->toBe($scoreBefore)
        ->and($after?->average_rating)->toBe($averageBefore);

    // And it is a real number rather than the null a profile with too little
    // history returns — otherwise both sides would be null and this would pass
    // against an implementation that had broken the calculation entirely.
    expect($scoreBefore)->not->toBeNull();
});

/*
| The mixed database, which is what the platform actually has the day after this
| ships: old rows with no axes beside new ones that have them. The average is over
| `rating` in both cases, so it is the plain mean of the five.
*/
it('mixes pre-010 and post-010 rows without either shape distorting the other', function (): void {
    preSpecTenReviews(5, 3);

    $student = studentWhoAttendedWith($this->teacher);
    postReview($student, $this->teacher->uuid, 4)->assertStatus(201);

    app(RecalculateTrustScore::class)->handle($this->teacher->fresh());

    $teacher = $this->teacher->fresh();

    expect((float) $teacher?->average_rating)->toBe(4.0)
        ->and($teacher?->reviews_count)->toBe(3);

    // The new row's star is DERIVED from its axes — the one place the two shapes
    // meet, and the reason `rating` stays the only column the average reads.
    $newest = Review::query()->withoutWorkspaceScope()->where('student_id', $student->getKey())->firstOrFail();

    expect($newest->rating)->toBe(4)
        ->and($newest->punctuality)->toBe(4);
});
