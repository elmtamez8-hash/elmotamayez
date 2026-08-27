<?php

declare(strict_types=1);

use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\MoveMember;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| FR-046 — الخيطُ السابقُ يبقى مقروءاً، ولا بدَّ أن يكونَ **موصولاً**.
|
| ⚠️ الـAPI منحَ القراءةَ الدائمةَ في ح-٤ ولم يكنْ في الواجهةِ رابطٌ يصلُ إليها:
| صلاحيّةٌ خلفَ معرّفٍ لم يُعرَضْ على أحد. هذا المفتاحُ هو البابُ، فاختفاؤه يعيدُ
| الأرشيفَ إلى ما كان عليه بلا أن يُسقِطَ اختباراً واحداً في ح-٤.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->fx = cohortFixture();
    $this->asGuest();
});

function pickerFor(object $test): array
{
    Sanctum::actingAs($test->fx['student']);

    // ⚠️ `asGuest()` is `protected` and Pest's file-level functions are not
    // methods of the test case, so calling it here is a fatal error rather than
    // a failure. `WorkspaceContext` caches its first resolution, and a fixture
    // built inside `forWorkspace()` would otherwise lend the student a context
    // production never gives them.
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    return $test->getJson('/api/v1/courses/'.$test->fx['course']->uuid.'/cohorts')
        ->assertOk()
        ->json();
}

it('names the group the student left, and never the one they are in now', function (): void {
    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);

    app(WorkspaceContext::class)->forWorkspace($this->fx['workspace'], function (): void {
        app(MoveMember::class)->handle($this->fx['b'], $this->fx['student'], $this->fx['owner']);
    });

    $this->asGuest();
    $payload = pickerFor($this);

    expect($payload['membership']['cohort_uuid'])->toBe((string) $this->fx['b']->uuid)
        ->and($payload['past_cohorts'])->toHaveCount(1)
        ->and($payload['past_cohorts'][0]['uuid'])->toBe((string) $this->fx['a']->uuid)
        // The current group is `membership`; listing it here as well would offer
        // the live thread as an archive of itself.
        ->and(collect($payload['past_cohorts'])->pluck('uuid'))->not->toContain((string) $this->fx['b']->uuid);
});

/*
| ⚠️ إعادةُ الانضمامِ تكتبُ صفّاً جديداً — التاريخُ لا يُعادُ كتابته — فطالبٌ خرجَ
| وعادَ له صفّان مُغلَقان لخيطٍ واحد. مفتاحُ التفريدِ هو المجموعةُ لا الصفّ.
*/
it('names a group once however many times the student left it', function (): void {
    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);

    app(WorkspaceContext::class)->forWorkspace($this->fx['workspace'], function (): void {
        app(MoveMember::class)->handle($this->fx['b'], $this->fx['student'], $this->fx['owner']);
        app(MoveMember::class)->handle($this->fx['a'], $this->fx['student'], $this->fx['owner']);
        app(MoveMember::class)->handle($this->fx['b'], $this->fx['student'], $this->fx['owner']);
    });

    $this->asGuest();

    expect(pickerFor($this)['past_cohorts'])->toHaveCount(1);
});

it('answers an empty list for a student who has never left a group', function (): void {
    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);

    expect(pickerFor($this)['past_cohorts'])->toBe([]);
});
