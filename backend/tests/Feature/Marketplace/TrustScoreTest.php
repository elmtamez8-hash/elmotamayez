<?php

declare(strict_types=1);

use App\Modules\Marketplace\Actions\ConfirmComplaint;
use App\Modules\Marketplace\Actions\DismissComplaint;
use App\Modules\Marketplace\Actions\RecalculateTrustScore;
use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;

/**
 * The table in quickstart.md scenario 3, one case per test.
 *
 * The load-bearing rule is that "not enough history" is null, never zero: a score
 * of zero is a verdict, and handing it to a teacher on their first day is a
 * verdict nobody made.
 */
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace();
});

/** @param array<string, mixed> $attrs */
function teacherWithHistory(array $attrs, int $reviews = 0, int $rating = 5): TeacherProfile
{
    $teacher = marketplaceTeacher(test()->workspace, $attrs);

    app(WorkspaceContext::class)->forWorkspace(test()->workspace, function () use ($teacher, $reviews, $rating): void {
        Review::factory()->count($reviews)->create([
            'workspace_id' => test()->workspace->getKey(),
            'teacher_profile_id' => $teacher->getKey(),
            'rating' => $rating,
        ]);
    });

    return $teacher;
}

it('reports "building" rather than zero below the data threshold', function (): void {
    $teacher = teacherWithHistory([
        'completed_sessions_count' => 5,
        'cancelled_sessions_count' => 0,
        'attendance_rate' => 100,
        'first_session_at' => now()->subMonths(2),
    ], reviews: 2);

    app(RecalculateTrustScore::class)->handle($teacher);

    expect($teacher->fresh()?->trust_score)->toBeNull()
        ->and($teacher->fresh()?->trustScoreBand())->toBe(TeacherProfile::BAND_BUILDING)
        ->and($teacher->fresh()?->trust_score_factors)->toBeNull();
});

it('scores a teacher with enough history from the configured weights', function (): void {
    $teacher = teacherWithHistory([
        'completed_sessions_count' => 12,
        'cancelled_sessions_count' => 0,
        'attendance_rate' => 90,
        'first_session_at' => now()->subYears(2),
    ], reviews: 4, rating: 5);

    app(RecalculateTrustScore::class)->handle($teacher);

    // rating 100×0.35 + punctuality 90×0.25 + completion 100×0.25 + tenure 100×0.15
    expect($teacher->fresh()?->trust_score)->toBe(98)
        ->and($teacher->fresh()?->trust_score_factors)->toMatchArray([
            'student_rating' => 100,
            'punctuality' => 90,
            'completion' => 100,
            'tenure' => 100,
            'complaints_penalty' => 0,
        ])
        ->and($teacher->fresh()?->trust_score_calculated_at)->not->toBeNull();
});

it('deducts five points per confirmed complaint and stops at twenty', function (int $complaints, int $expectedPenalty): void {
    $teacher = teacherWithHistory([
        'completed_sessions_count' => 12,
        'cancelled_sessions_count' => 0,
        'attendance_rate' => 90,
        'first_session_at' => now()->subYears(2),
    ], reviews: 4, rating: 5);

    app(RecalculateTrustScore::class)->handle($teacher);
    $before = (int) $teacher->fresh()?->trust_score;

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($teacher, $complaints): void {
        Complaint::factory()->count($complaints)->confirmed()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $teacher->getKey(),
        ]);
    });

    app(RecalculateTrustScore::class)->handle($teacher);

    expect($before - (int) $teacher->fresh()?->trust_score)->toBe($expectedPenalty);
})->with([
    'one complaint' => [1, 5],
    'three complaints' => [3, 15],
    'capped at four' => [4, 20],
    'still capped at ten' => [10, 20],
]);

it('counts only confirmed complaints', function (): void {
    $teacher = teacherWithHistory([
        'completed_sessions_count' => 12,
        'cancelled_sessions_count' => 0,
        'attendance_rate' => 90,
        'first_session_at' => now()->subYears(2),
    ], reviews: 4, rating: 5);

    $complaint = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn () => Complaint::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $teacher->getKey(),
        ]),
    );

    app(RecalculateTrustScore::class)->handle($teacher);
    $untouched = (int) $teacher->fresh()?->trust_score;

    app(DismissComplaint::class)->handle($complaint);
    app(RecalculateTrustScore::class)->handle($teacher);

    expect((int) $teacher->fresh()?->trust_score)->toBe($untouched);

    // Confirming the same complaint is what costs points, and it recalculates on
    // its own — the caller does not have to remember to.
    app(ConfirmComplaint::class)->handle($complaint);

    expect((int) $teacher->fresh()?->trust_score)->toBe($untouched - 5);
});

it('never stores a score outside 0–100', function (): void {
    // Every factor maxed and no complaints is the top of the range; the column is
    // unsignedTinyInteger, and SQLite would happily store 101 for MySQL to reject
    // in production.
    $teacher = teacherWithHistory([
        'completed_sessions_count' => 50,
        'cancelled_sessions_count' => 0,
        'attendance_rate' => 100,
        'first_session_at' => now()->subYears(5),
    ], reviews: 5, rating: 5);

    app(RecalculateTrustScore::class)->handle($teacher);

    expect($teacher->fresh()?->trust_score)->toBe(100);
});

it('reflects a new review in the public listing through the queue', function (): void {
    $teacher = marketplaceTeacher($this->workspace, [
        'completed_sessions_count' => 12,
        'cancelled_sessions_count' => 0,
        'attendance_rate' => 90,
        'first_session_at' => now()->subYears(2),
        'reviews_count' => 0,
        'average_rating' => null,
        'trust_score' => null,
    ]);

    $students = collect(range(1, 3))->map(fn () => studentWhoAttendedWith($teacher));

    foreach ($students as $student) {
        postReview($student, $teacher->uuid, 5);
    }

    $this->asGuest();
    $card = $this->getJson('/api/v1/marketplace/teachers')->json('data.0');

    expect($card['reviews_count'])->toBe(3)
        ->and((float) $card['average_rating'])->toBe(5.0)
        ->and($card['trust_score'])->not->toBeNull()
        ->and($card['trust_score_band'])->not->toBe(TeacherProfile::BAND_BUILDING);
});

it('excludes teachers with no score from a minimum-score filter', function (): void {
    $scored = marketplaceTeacher($this->workspace, ['trust_score' => 90]);
    marketplaceTeacher($this->workspace, ['trust_score' => null]);
    marketplaceTeacher($this->workspace, ['trust_score' => 40]);

    $this->asGuest();
    $response = $this->getJson('/api/v1/marketplace/teachers?min_trust_score=60');

    // "Building" is not "below 60" — it is "no answer yet", so it drops out of a
    // numeric comparison rather than losing it (FR-026).
    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.uuid'))->toBe($scored->uuid);
});
