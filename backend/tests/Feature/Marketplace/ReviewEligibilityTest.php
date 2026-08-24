<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\Review;
use App\Modules\Tenancy\Support\PlatformSettings;
use Laravel\Sanctum\Sanctum;

/*
| `SC-010` — zero reviews from a student below the threshold, zero repeats inside
| one period, AND the offered eligibility matches what the server accepts.
|
| ⚠️ THE THIRD CLAUSE IS THE ONE THAT NEEDS A TEST. The first two are ordinary
| refusals and a reader can check them by eye. The third is a claim about TWO
| pieces of code agreeing, and the only way to measure it is to take the answer the
| endpoint offers and walk it through the endpoint that decides — which is what the
| last case here does. `ListLeaderboardScopes` is where this repository learnt that
| a list assembled beside its authoriser offers what the server refuses and hides
| what it allows.
*/

beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace();
    $this->teacher = marketplaceTeacher($this->workspace);
});

it('refuses a student below the attendance threshold, and says what is missing', function (): void {
    // One short of the four `CommunitySettings::reviewMinSessions()` defaults to.
    $student = studentWhoAttendedWith($this->teacher, 3);

    $response = postReview($student, $this->teacher->uuid)->assertStatus(422);

    // ⚠️ THE REFUSAL NAMES THE NUMBERS. «You may not» leaves a student who is two
    // lessons away with nothing to act on, which is how a form gets abandoned.
    expect((string) $response->json('message'))->toContain('3');

    expect(Review::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('accepts the same student once they reach it', function (): void {
    $student = studentWhoAttendedWith($this->teacher, 4);

    postReview($student, $this->teacher->uuid)->assertStatus(201);

    expect(Review::query()->withoutWorkspaceScope()->count())->toBe(1);
});

/*
| ⚠️ THE THRESHOLD IS A ROW, NOT A CONSTANT. Read from `platform_settings` through
| `CommunitySettings`, so an operator raises it from the panel — and this case is
| what proves the read happens at ask time rather than being baked into a class
| constant that only a deploy can move.
*/
it('follows the threshold an operator sets', function (): void {
    PlatformSettings::set('community.review.min_sessions', 6);

    $student = studentWhoAttendedWith($this->teacher, 4);

    postReview($student, $this->teacher->uuid)->assertStatus(422);
});

/*
| `FR-032`. The shipped unique was the pair alone, which made this true for free
| and made a SECOND period impossible for ever — so the assertion is that a repeat
| inside the window revises the one row, not that the write is refused.
*/
it('keeps one row per period however often the student writes in it', function (): void {
    $student = studentWhoAttendedWith($this->teacher);

    postReview($student, $this->teacher->uuid, 5)->assertStatus(201);
    postReview($student, $this->teacher->uuid, 2)->assertStatus(200);
    postReview($student, $this->teacher->uuid, 3)->assertStatus(200);

    $reviews = Review::query()->withoutWorkspaceScope()->get();

    expect($reviews)->toHaveCount(1)
        ->and($reviews->first()?->rating)->toBe(3);
});

it('opens a new period once the old one has run out', function (): void {
    $student = studentWhoAttendedWith($this->teacher);

    postReview($student, $this->teacher->uuid, 5)->assertStatus(201);

    // The window is `community.review.period_days` long (30 by default).
    $this->travel(31)->days();

    postReview($student, $this->teacher->uuid, 2)->assertStatus(201);

    expect(Review::query()->withoutWorkspaceScope()->count())->toBe(2);
});

/*
| ⚠️ THE OFFER IS WALKED THROUGH THE DOOR. Two spellings of «may this student rate
| this teacher» put one answer on the screen and another at the endpoint — and the
| screen is the one the student believes. Asserting the payload's SHAPE would pass
| against two implementations that disagree.
*/
it('offers exactly what the endpoint accepts, in both directions', function (): void {
    $tooEarly = studentWhoAttendedWith($this->teacher, 2);
    $ready = studentWhoAttendedWith($this->teacher, 4);

    foreach ([$tooEarly, $ready] as $student) {
        Sanctum::actingAs($student);
        $this->asGuest();

        $offer = $this->getJson("/api/v1/teachers/{$this->teacher->uuid}/reviews/eligibility")
            ->assertOk()
            ->json();

        $actual = postReview($student, $this->teacher->uuid);

        expect($offer['eligible'])->toBe(in_array($actual->status(), [200, 201], true));

        // And the count it showed is the count it refused on, not a second tally.
        expect($offer['attended_sessions'])->toBe($student === $tooEarly ? 2 : 4);
    }
});

it('refuses to let a teacher rate themselves, and says so before the form opens', function (): void {
    $teacherUser = $this->teacher->user;

    Sanctum::actingAs($teacherUser);
    $this->asGuest();

    $this->getJson("/api/v1/teachers/{$this->teacher->uuid}/reviews/eligibility")
        ->assertOk()
        ->assertJsonPath('eligible', false);

    postReview($teacherUser, $this->teacher->uuid)->assertStatus(403);
});
