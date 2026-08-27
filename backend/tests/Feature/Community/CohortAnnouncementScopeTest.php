<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\Announcement;
use App\Modules\Community\Support\AnnouncementAudience;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| FR-042 — تنبيهُ المجموعةِ يبلغُ أعضاءَها وحدَهم.
|
| ⚠️ والجمهورُ يُقاسُ من `AnnouncementAudience` نفسِها، وهي المكانُ الوحيدُ الذي
| يُجيبُ السؤال: التوزيعُ يقرؤها، و«كم بلغَهم الإعلان» على شاشةِ المدرّسِ يقرؤها.
| مكتوبةً مرّتَين تختلفان أوّلَ ما يكتسبُ نطاقٌ شرطاً، ويُعرَضُ على المدرّسِ مقامٌ
| لم يُرسَلْ إلى أحد.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->fx = cohortFixture();
    $this->asGuest();
});

/** A second real student of the same course, in whichever group is named. */
function memberOf(object $test, string $key): User
{
    $fx = $test->fx;
    $student = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $student): void {
        Enrollment::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    });

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    app(JoinCohort::class)->handle($fx[$key], $student);

    return $student;
}

/** The audience of an unsaved announcement with this scope. */
function audienceFor(object $test, string $scope, ?int $scopeId): array
{
    $announcement = new Announcement([
        'workspace_id' => $test->fx['workspace']->getKey(),
        'scope' => $scope,
    ]);

    $announcement->scope_id = $scopeId;

    return app(AnnouncementAudience::class)->userIdsFor($announcement);
}

it('reaches only the members of the group it names', function (): void {
    $inA = memberOf($this, 'a');
    $inB = memberOf($this, 'b');

    $audience = audienceFor($this, Announcement::SCOPE_COHORT, (int) $this->fx['a']->getKey());

    expect($audience)->toContain((int) $inA->getKey());
    expect($audience)->not->toContain((int) $inB->getKey());
});

/*
| ⚠️ AND THE COURSE-WIDE NOTICE STILL REACHES EVERY GROUP. FR-036: a course that
| runs in groups did not stop being one course, and a scope that quietly narrowed
| to the teacher's newest group would be a silent loss on the notice most likely
| to matter.
*/
it('leaves the course scope reaching every group', function (): void {
    $inA = memberOf($this, 'a');
    $inB = memberOf($this, 'b');

    $audience = audienceFor($this, Announcement::SCOPE_COURSE, (int) $this->fx['course']->getKey());

    expect($audience)->toContain((int) $inA->getKey());
    expect($audience)->toContain((int) $inB->getKey());
});

/*
| ⚠️ THE MEMBER WHO LEFT IS NOT TOLD ABOUT A SATURDAY THEY WILL NOT BE ON, and
| this is the deliberate MIRROR of the thread's read rule, which does still reach
| them. Keeping an old answer is a right; being told about a future you are not
| part of is noise. `activeMemberIdsFor()` is what draws the line, and reading
| «was ever a member» here instead would erase it.
*/
it('stops reaching a member after they transfer out', function (): void {
    $mover = memberOf($this, 'a');

    expect(audienceFor($this, Announcement::SCOPE_COHORT, (int) $this->fx['a']->getKey()))
        ->toContain((int) $mover->getKey());

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    app(MoveMember::class)->handle($this->fx['b'], $mover, $this->fx['owner']);

    expect(audienceFor($this, Announcement::SCOPE_COHORT, (int) $this->fx['a']->getKey()))
        ->not->toContain((int) $mover->getKey());

    // And they are now in the other group's audience — the move is a move, not a
    // removal.
    expect(audienceFor($this, Announcement::SCOPE_COHORT, (int) $this->fx['b']->getKey()))
        ->toContain((int) $mover->getKey());
});

/*
| ⚠️ AN UNKNOWN SCOPE REACHES NOBODY, AND A COHORT SCOPE WITH NO TARGET REACHES
| NOBODY EITHER. `default => []` is the load-bearing line of that class: a scope
| it does not understand must not become a broadcast to every student the teacher
| has — and `(int) null === 0`, so a null target left unguarded would address
| whatever row happens to carry id 0.
*/
it('reaches nobody when the group is not named', function (): void {
    memberOf($this, 'a');

    expect(audienceFor($this, Announcement::SCOPE_COHORT, null))->toBe([]);
    expect(audienceFor($this, 'nonsense', 1))->toBe([]);
});
