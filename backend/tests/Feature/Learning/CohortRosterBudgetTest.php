<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| SC-013 — كلفةُ القائمةِ لا تنمو بعددِ الأعضاء.
|
| ⚠️ تجهيزان بحجمين، لا واحد. سقفٌ ثابتٌ على تجهيزِ خمسةٍ يمرُّ على تنفيذٍ يسألُ
| صفّاً صفّاً؛ والمساواةُ عندَ حجمٍ واحدٍ تقيسُ التنفيذَ ضدَّ نفسِه.
|
| ⚠️ وحضورُ الحقلِ يُؤكَّدُ مع العدد. إسقاطُ الجلبِ المُتلهِّفِ **لا يُنتِجُ N+1**:
| المفتاحُ يغيبُ، والصفحةُ تصيرُ أرخصَ، وميزانٌ يقيسُ الكلفةَ وحدَها يُبلِّغُ عن
| التراجعِ تحسّناً — ثمّ تُعرَضُ القائمةُ بلا اسمِ أحدٍ ولا نيشان.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{0: User, 1: Cohort} the reader, and the group they are all in */
function rosterOfSize(object $test, int $members): array
{
    $fx = cohortFixture();

    $badge = Badge::factory()->create(['key' => 'streak_'.$members, 'name' => 'مواظبة']);

    $students = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $members): array {
        $made = [];

        for ($i = 0; $i < $members; $i++) {
            $student = User::factory()->create();

            Enrollment::create([
                'workspace_id' => $fx['workspace']->getKey(),
                'course_id' => $fx['course']->getKey(),
                'student_user_id' => $student->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);

            $made[] = $student;
        }

        return $made;
    });

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    foreach ($students as $student) {
        app(JoinCohort::class)->handle($fx['a'], $student);

        // ⚠️ A BADGE ON EVERY ROW, or "the badges are present" is a claim about
        // an empty list that a dropped read would satisfy just as well.
        BadgeAward::factory()->create([
            'user_id' => $student->getKey(),
            'badge_key' => $badge->key,
            'awarded_at' => now(),
        ]);
    }

    return [$students[0], $fx['a']];
}

/** @return array{0: int, 1: array<int, array<string, mixed>>} */
function measureRoster(object $test, int $members): array
{
    [$reader, $cohort] = rosterOfSize($test, $members);

    Sanctum::actingAs($reader);
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    $url = '/api/v1/cohorts/'.$cohort->uuid.'/roster';

    // Warm-up: spatie's permission cache is filled by the first authenticated
    // request in the process, so an unwarmed measurement carries the fill and a
    // warmed one does not — the bigger page then looks cheaper by a fixed
    // handful, and an N+1 five rows deep hides in the difference.
    $test->getJson($url)->assertOk();

    [$count, $response] = countingQueries(fn () => $test->getJson($url)->assertOk());

    return [$count, $response->json('members')];
}

it('answers five members and fifty in the same number of queries, with names and badges on every row', function (): void {
    [$small, $smallRows] = measureRoster($this, 5);
    [$large, $largeRows] = measureRoster($this, 50);

    expect($smallRows)->toHaveCount(5)
        ->and($largeRows)->toHaveCount(50)
        ->and($large)->toBe($small);

    foreach ([$smallRows, $largeRows] as $rows) {
        foreach ($rows as $row) {
            expect($row['name'])->not->toBe('')
                // A single space is what a constrained eager load naming `name`
                // returns — an empty accessor over two absent columns.
                ->and(trim((string) $row['name']))->not->toBe('')
                ->and($row['badges'])->toHaveCount(1);
        }
    }
});
