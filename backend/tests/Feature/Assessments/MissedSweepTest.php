<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GrantAccommodation;
use App\Modules\Assessments\Actions\GrantExtension;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Jobs\MarkMissedSubmissionsJob;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Support\ApplyAccommodation;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/*
| FR-051. The deadline passes and the register writes itself.
*/

it('writes a missed row per student, each with a real uuid', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $first = $this->addWorkspaceMember($workspace);
    $second = $this->addWorkspaceMember($workspace);

    [$assignment, $course] = courseAssignment($workspace, $owner, $first, ['due_at' => now()->subDay()]);
    $this->createEnrollment($workspace, $course, $second);

    app(MarkMissedSubmissionsJob::class)->handle(app(WorkspaceContext::class), app(ApplyAccommodation::class));

    $rows = Submission::query()->withoutWorkspaceScope()->where('assignment_id', $assignment->getKey())->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('state')->unique()->all())->toBe([Submission::STATE_MISSED]);

    /*
    | ⚠️ THE UUIDs ARE THE ASSERTION. `insertOrIgnore` boots no model, so
    | `HasUuid` never fires — and on MySQL the resulting NOT NULL violation is
    | DOWNGRADED TO A WARNING and `''` is stored. After that, every later
    | submission on the platform collides with that row on `unique(uuid)`, is
    | read as "already recorded", and is silently skipped: the sweep stops after
    | one row while reporting success, for ever. SQLite would not reproduce it,
    | which is exactly why the column is checked rather than assumed.
    */
    foreach ($rows as $row) {
        expect($row->uuid)->not->toBe('')
            ->and($row->uuid)->toMatch('/^[0-9a-f-]{36}$/i');
    }

    // Idempotent: a second night writes nothing new rather than colliding.
    app(MarkMissedSubmissionsJob::class)->handle(app(WorkspaceContext::class), app(ApplyAccommodation::class));

    expect(DB::table('submissions')->where('assignment_id', $assignment->getKey())->count())->toBe(2);
});

it('never writes over work that was handed in', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$assignment] = courseAssignment($workspace, $owner, $student, [
        'due_at' => now()->subHour(),
        'late_policy' => Assignment::LATE_ACCEPT,
    ]);

    $submitted = app(SubmitAssignment::class)->handle($assignment, $student, 'متأخر لكنه موجود.');

    expect($submitted->state)->toBe(Submission::STATE_LATE);

    app(MarkMissedSubmissionsJob::class)->handle(app(WorkspaceContext::class), app(ApplyAccommodation::class));

    /*
    | ⚠️ A BLIND UPDATE HERE ERASES WORK. The row exists and its deadline has
    | passed; writing `missed` over it throws away a hand-in the teacher would
    | then never see, and the student's only evidence that they did it.
    */
    expect($submitted->refresh()->state)->toBe(Submission::STATE_LATE)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and($submitted->answer_text)->toBe('متأخر لكنه موجود.');
});

it('leaves alone the student who was given longer', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $extended = $this->addWorkspaceMember($workspace);
    $accommodated = $this->addWorkspaceMember($workspace);
    $ordinary = $this->addWorkspaceMember($workspace);

    [$assignment, $course] = courseAssignment($workspace, $owner, $extended, ['due_at' => now()->subDay()]);
    $this->createEnrollment($workspace, $course, $accommodated);
    $this->createEnrollment($workspace, $course, $ordinary);

    // One by an explicit extension on this assignment…
    app(GrantExtension::class)->handle($assignment, $owner, $extended, now()->addDays(2));

    // …and one by the standing arrangement, which the sweep must consult too.
    app(GrantAccommodation::class)->handle(
        (int) $workspace->getKey(),
        $owner,
        $accommodated,
        extraTimePct: 0,
        extendedDays: 3,
        reason: 'ترتيبٌ قائم.',
    );

    app(MarkMissedSubmissionsJob::class)->handle(app(WorkspaceContext::class), app(ApplyAccommodation::class));

    $stateOf = fn ($user): ?string => Submission::query()
        ->withoutWorkspaceScope()
        ->where('assignment_id', $assignment->getKey())
        ->where('student_user_id', $user->getKey())
        ->value('state');

    /*
    | ⚠️ THE FIRST PERSON THE SWEEP WOULD HAVE HURT IS THE ONE IT WAS SUPPOSED TO
    | PROTECT. A `missed` stamp on the night of the original deadline is then read
    | by US7's unlock gate, which shuts the next session on a student who was
    | told, in writing, that they had until Thursday.
    */
    expect($stateOf($extended))->toBe(Submission::STATE_PENDING)
        ->and($stateOf($accommodated))->toBeNull()
        ->and($stateOf($ordinary))->toBe(Submission::STATE_MISSED);
});

it('marks the row once the extension itself has run out', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->subDays(5)]);

    app(GrantExtension::class)->handle($assignment, $owner, $student, now()->addMinutes(5));

    $row = Submission::query()
        ->where('assignment_id', $assignment->getKey())
        ->where('student_user_id', $student->getKey())
        ->sole();

    // The extension lapses without a hand-in.
    $row->forceFill(['extension_until' => now()->subMinute()])->save();

    expect($row->state)->toBe(Submission::STATE_PENDING);

    app(MarkMissedSubmissionsJob::class)->handle(app(WorkspaceContext::class), app(ApplyAccommodation::class));

    expect($row->refresh()->state)->toBe(Submission::STATE_MISSED)
        ->and($row->submitted_at)->toBeNull();
});
