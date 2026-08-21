<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ExportFieldAllowlist;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExportArchive;

/**
 * SC-005 · FR-016 · FR-017 — everything of theirs, nothing of anybody else's.
 *
 * ⚠️ TWO WORKSPACES, AND ONE WOULD PROVE NOTHING. `data_requests` carries no
 * `workspace_id` on purpose: a student studies with several teachers and one
 * request covers all of them. But every table the walk touches IS tenant-scoped,
 * and `WorkspaceContext::id()` falls back to `users.last_workspace_id` for anybody
 * — so an implementor that forgot `withoutWorkspaceScope()` returns a perfectly
 * plausible archive containing exactly one teacher's half, and a single-workspace
 * fixture calls that a pass. This is the same defect that shipped in the audit
 * chain and answered "nothing was bought" with a 200.
 *
 * ⚠️ AND THE FOREIGN SENTINEL IS ASCII. `getContent()` and `json_encode` escape
 * non-ASCII, so an assertion that an Arabic name is absent NEVER MATCHES whatever
 * the payload holds. {@see ExportArchive::text()} re-encodes with
 * `JSON_UNESCAPED_UNICODE`; the sentinel is ASCII as well, belt and braces, because
 * this is the one file where a vacuous pass means a leak nobody sees.
 */
const FOREIGN_SENTINEL = 'ZZ-NOT-YOUR-CLASSMATE-ZZ';

function exportFixtureCourse(Workspace $workspace, string $title): Course
{
    return app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'title' => $title,
    ]));
}

function enrolInWorkspace(Workspace $workspace, User $student, Course $course): Enrollment
{
    return app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Enrollment => Enrollment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => 'active',
    ]));
}

function completedExportFor(User $subject): DataRequest
{
    $request = app(CreateDataRequest::class)->handle($subject, (string) $subject->uuid, DataRequestType::Export);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    return $request->refresh();
}

beforeEach(function (): void {
    Storage::fake('local');

    [$first] = $this->createWorkspaceWithOwner(['name' => 'First Academy']);
    [$second] = $this->createWorkspaceWithOwner(['name' => 'Second Academy']);

    $this->first = $first;
    $this->second = $second;

    /*
    | ⚠️ `last_workspace_id` IS SET, AND WITHOUT IT THIS FILE PROVES NOTHING.
    | `WorkspaceScope` adds no condition when the context resolves to null, and a
    | student is a member of no workspace at all — so a fixture that leaves the
    | column empty makes every missing `withoutWorkspaceScope()` invisible, and the
    | cross-workspace assertion below passes against code that has none. The column
    | is what `WorkspaceContext` falls back to for ANY user, and it is set here to
    | the first teacher, which is what a real student who last opened that teacher's
    | page carries.
    */
    $this->student = User::factory()->create(['first_name' => 'Own', 'last_name' => 'Student']);
    $this->student->forceFill(['last_workspace_id' => $first->getKey()])->save();
    $this->classmate = User::factory()->create(['first_name' => FOREIGN_SENTINEL, 'last_name' => 'Classmate']);

    enrolInWorkspace($first, $this->student, exportFixtureCourse($first, 'دورة المدرّس الأول'));
    enrolInWorkspace($second, $this->student, exportFixtureCourse($second, 'دورة المدرّس الثاني'));

    // Somebody else's row in the SAME workspace and the SAME table — the shape a
    // missing `where student_user_id` produces.
    enrolInWorkspace($first, $this->classmate, exportFixtureCourse($first, 'دورة الزميل'));

    /*
    | ⚠️ THE CONTEXT IS LEFT RESOLVED TO THE FIRST WORKSPACE, ON PURPOSE, AND THIS
    | IS THE LINE THAT GIVES THE FILE ITS TEETH.
    |
    | `WorkspaceScope` adds no condition when the context is null, and it IS null in
    | a queue worker that has handled nothing else — so a fixture that cleared it
    | would make every missing `withoutWorkspaceScope()` invisible, and the
    | cross-workspace assertion below would pass against a walk that has none. That
    | was measured: with the bypass deleted from `LearningPersonalData`, the cleared
    | version of this test stayed green.
    |
    | A resolved context is not a contrivance either. `WorkspaceContext` is an
    | application-wide singleton that caches its answer, which is exactly why this
    | repository forbids `set()` inside a job — a worker that ran anything else
    | first carries that workspace into the next thing it handles. The export must
    | be complete regardless, and this is that requirement written down.
    */
    app(WorkspaceContext::class)->set($first);
});

it('reaches every workspace the subject studies in', function (): void {
    $request = completedExportFor($this->student);

    expect($request->status)->toBe(DataRequestStatus::Completed);

    $enrollments = ExportArchive::rows($request, 'enrollment_record');
    $titles = array_column($enrollments, 'course_title');

    expect($titles)->toContain('دورة المدرّس الأول')
        ->and($titles)->toContain('دورة المدرّس الثاني')
        ->and($titles)->not->toContain('دورة الزميل');
});

it('carries no other person anywhere in the archive', function (): void {
    $request = completedExportFor($this->student);

    expect(ExportArchive::text($request))->not->toContain(FOREIGN_SENTINEL);
});

it('carries no forbidden key in any file, at any depth', function (): void {
    $request = completedExportFor($this->student);

    $leaks = [];

    foreach (ExportArchive::read($request) as $name => $contents) {
        if (! str_ends_with($name, '.json')) {
            continue;
        }

        foreach (ExportFieldAllowlist::leaks($contents, $name) as $leak) {
            $leaks[] = $leak;
        }
    }

    expect($leaks)->toBe([]);
});

/*
 * ⚠️ AN EMPTY FILE IS AN ANSWER; A MISSING FILE IS SILENCE.
 *
 * "We hold nothing of this kind about you" is what a person asking is entitled to
 * be told, and it is the half of a rights request that no other test would notice
 * missing: an archive that simply omitted the categories with no rows would look
 * complete, be smaller, and answer nothing.
 */
it('writes a file for every category, including the empty ones', function (): void {
    $request = completedExportFor($this->student);
    $files = ExportArchive::read($request);

    expect($files)->toHaveKey('README.md')
        ->and($files)->toHaveKey('manifest.json')
        ->and($files)->toHaveKey('data/certificate.json')
        ->and($files['data/certificate.json'])->toBe([]);
});

it('names every file it wrote in the readme, in Arabic', function (): void {
    $request = completedExportFor($this->student);
    $readme = (string) ExportArchive::read($request)['README.md'];

    expect($readme)->toContain('نسخةٌ من بياناتك')
        ->and($readme)->toContain('data/enrollment_record.json')
        // The archive addresses one person, and the request that produced it has to
        // be identifiable from inside the file — an archive with no request number
        // cannot be matched to the record that says it was answered.
        ->and($readme)->toContain((string) $request->uuid);
});
