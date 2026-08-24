<?php

declare(strict_types=1);

use App\Modules\Community\Actions\SaveGradingScheme;
use App\Modules\Community\Data\GradingSchemeData;
use App\Modules\Community\Models\GradingScheme;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
| `SC-017` — zero saved weightings that do not add up to 100.
|
| ⚠️ THE ACTION IS TESTED DIRECTLY AS WELL AS THROUGH THE ENDPOINT, and that is
| the whole point of the file rather than a belt-and-braces flourish. FR-050 says
| a scheme that does not reach 100 may not be SAVED — a statement about the row,
| not about one payload — and the Action is the entry point the seeders, the
| Filament panel and the API all share. A rule living only in a `FormRequest`
| holds for whichever screen happens to exist today, which is exactly how
| `Question`'s "exactly one correct option" arrived in three places.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);
});

/** @param array<string, int> $weights */
function schemeData(array $weights, ?string $courseUuid = null): GradingSchemeData
{
    return GradingSchemeData::fromArray([
        'course_uuid' => $courseUuid,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'weights' => $weights,
    ]);
}

it('saves a weighting that adds up to exactly 100', function (): void {
    $scheme = app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 60,
        'homework' => 30,
        'attendance' => 10,
        'participation' => 0,
    ]));

    expect($scheme->weights)->toBe([
        'exams' => 60,
        'homework' => 30,
        'attendance' => 10,
        'participation' => 0,
    ]);

    expect(GradingScheme::query()->count())->toBe(1);
});

it('refuses a weighting below 100 in the action itself', function (): void {
    expect(fn () => app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 60,
        'homework' => 30,
        'attendance' => 5,
        'participation' => 0,
    ])))->toThrow(ValidationException::class);

    expect(GradingScheme::query()->count())->toBe(0);
});

it('refuses a weighting above 100 in the action itself', function (): void {
    expect(fn () => app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 60,
        'homework' => 30,
        'attendance' => 30,
        'participation' => 0,
    ])))->toThrow(ValidationException::class);

    expect(GradingScheme::query()->count())->toBe(0);
});

it('refuses a component nobody has heard of', function (): void {
    expect(fn () => app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 50,
        'vibes' => 50,
    ])))->toThrow(ValidationException::class);

    expect(GradingScheme::query()->count())->toBe(0);
});

it('refuses the endpoint too, so the screen and the action agree', function (): void {
    $this->teacher->givePermissionTo(Permissions::REVIEWS_PERIODIC_MANAGE);
    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/manage/grading-schemes', [
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'weights' => ['exams' => 50, 'homework' => 30, 'attendance' => 10, 'participation' => 5],
    ])->assertStatus(422);

    expect(GradingScheme::query()->count())->toBe(0);
});

it('rewrites its own row rather than adding a second for one period', function (): void {
    app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 60, 'homework' => 30, 'attendance' => 10, 'participation' => 0,
    ]));

    app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 40, 'homework' => 40, 'attendance' => 20, 'participation' => 0,
    ]));

    expect(GradingScheme::query()->count())->toBe(1);
    expect(GradingScheme::query()->first()?->weights['exams'])->toBe(40);
});

/*
| ⚠️ THE SENTINEL, NOT A NULL. `course_id` is `NOT NULL DEFAULT 0` inside the
| unique quadruple because NULL never equals NULL: a nullable column there would
| let two workspace-wide schemes for one period both insert, and the build would
| pick whichever came back first. It is `concept_stats.lesson_id` and
| `unlock_rules.course_id` for the third time in this repository.
|
| The price of the sentinel is the mirror bug — `(int) null === 0`, so a uuid that
| resolves to nothing addresses the DEFAULT row and silently overwrites the
| workspace-wide scheme. That is the second case below.
*/
it('keeps a course scheme and the workspace-wide one apart', function (): void {
    $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 60, 'homework' => 30, 'attendance' => 10, 'participation' => 0,
    ]));

    app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 100, 'homework' => 0, 'attendance' => 0, 'participation' => 0,
    ], $course->uuid));

    expect(GradingScheme::query()->count())->toBe(2);
    expect(GradingScheme::query()->where('course_id', GradingScheme::ALL_COURSES)->first()?->weights['exams'])
        ->toBe(60);
});

it('refuses an unknown course rather than writing over the workspace-wide row', function (): void {
    app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 60, 'homework' => 30, 'attendance' => 10, 'participation' => 0,
    ]));

    expect(fn () => app(SaveGradingScheme::class)->handle(schemeData([
        'exams' => 100, 'homework' => 0, 'attendance' => 0, 'participation' => 0,
    ], (string) Str::uuid())))->toThrow(ValidationException::class);

    expect(GradingScheme::query()->count())->toBe(1);
    expect(GradingScheme::query()->first()?->weights['exams'])->toBe(60);
});
