<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Actions\RollUpLeaderboards;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Support\GamificationFieldAllowlist;
use App\Modules\Identity\Support\PlatformRole;
use Laravel\Sanctum\Sanctum;

/**
 * What a leaderboard row may say about a child (FR-027 · SC-021).
 *
 * Three of the six scopes cross workspaces by design, so a platform board is a
 * list of minors visible to every student on the site.
 */
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();

    /*
    | ⚠️ A DISTINCTIVE, MULTI-CHARACTER SURNAME — the fixture detail SC-021 names
    | explicitly. On a short name the abbreviation and the full form coincide, so
    | "the surname is not in the payload" passes VACUOUSLY against an
    | implementation that leaks everything. "الكواري" abbreviates to "ك." only if
    | the definite article is skipped first, which is a second thing this catches.
    */
    $this->student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'first_name' => 'خالد',
        'last_name' => 'الكواري',
    ]);

    $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    app(AwardPoints::class)->handle(new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'exposure',
        sourceId: 1,
        workspaceId: (int) $this->workspace->getKey(),
        courseId: (int) $course->getKey(),
    ));

    app(RollUpLeaderboards::class)->handle();
});

/**
 * The payload as TEXT, re-encoded so Arabic is readable.
 *
 * ⚠️ `getContent()` ESCAPES NON-ASCII, so `expect($response->getContent())
 * ->not->toContain('الكواري')` never matches whatever the body holds — the raw
 * body carries `الك...`. Every leak assertion in this product is
 * about Arabic text, so without this the whole file would pass against a
 * response that leaked the lot.
 */
function boardText(): string
{
    $response = test()->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk();

    return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

it('shows the abbreviated name and never the full surname', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $text = boardText();

    expect($text)->toContain('خالد ك.')
        ->and($text)->not->toContain('الكواري')
        // And the sentinel proves the assertion above can fail: the escaping trap
        // would make BOTH of these pass on any payload at all.
        ->and($text)->toContain('خالد');
});

it('carries no field outside the allowlist', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $entries = test()->getJson('/api/v1/gamification/leaderboard?scope=platform')->assertOk()->json('entries');

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        expect(array_keys($entry))->toEqualCanonicalizing(GamificationFieldAllowlist::fields());
    }
});

/*
 * ⚠️ THE OTHER HALF OF THE GUARD.
 *
 * An allowlist claims an ABSENCE, and a test that only checks the listed keys are
 * present proves nothing about what else came along. `user_uuid` heads the
 * forbidden list for a reason of its own: it is the JOIN KEY, and with it an
 * abbreviated name stops being a pseudonym and becomes a stable identifier
 * linking this row to every other surface that exposes the same uuid.
 */
it('carries none of the named forbidden fields, anywhere in the payload', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $text = boardText();

    foreach (GamificationFieldAllowlist::forbidden() as $field) {
        expect($text)->not->toContain('"'.$field.'"');
    }

    expect($text)->not->toContain($this->student->uuid)
        ->and($text)->not->toContain((string) $this->student->email);
});
