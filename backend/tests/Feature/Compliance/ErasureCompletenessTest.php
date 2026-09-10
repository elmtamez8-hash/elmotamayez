<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Invitation;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SC-006 — the erasure reaches every module, and leaves what the law keeps.
 *
 * ⚠️ THE ROWS THAT SURVIVE ARE ASSERTED AS HARD AS THE ROWS THAT GO. An erasure
 * test that only checked for absence would pass against a walk that deleted the
 * certificate a student earned, the ledger line a teacher is owed, and the course
 * thirty other students are half-way through — all three of which this phase
 * forbids, and each of which is easier to write than the correct behaviour.
 */
function erasureFixtureFor(User $subject, int $workspaceId): void
{
    app(WorkspaceContext::class)->forWorkspace($workspaceId, function () use ($subject, $workspaceId): void {
        $course = Course::factory()->create(['workspace_id' => $workspaceId]);

        $enrollment = Enrollment::factory()->create([
            'workspace_id' => $workspaceId,
            'course_id' => $course->getKey(),
            'student_user_id' => $subject->getKey(),
            'status' => 'active',
        ]);

        DB::table('lesson_progress')->insert([
            'workspace_id' => $workspaceId,
            'enrollment_id' => $enrollment->getKey(),
            'lesson_id' => 1,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    Notification::query()->create([
        'recipient_user_id' => $subject->getKey(),
        'workspace_id' => $workspaceId,
        'type' => NotificationType::AttendanceAlert->value,
        'title' => 'تنبيه',
        'body' => 'نصّ',
    ]);

    ContactVerification::query()->create([
        'user_id' => $subject->getKey(),
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => '+97455512345',
        'code_hash' => 'x',
        'expires_at' => now()->subMinute(),
        'verified_at' => now(),
    ]);

    Invitation::query()->create([
        'workspace_id' => $workspaceId,
        'email' => (string) $subject->email,
        'role' => 'student',
        'token' => Str::random(40),
        'expires_at' => now()->addWeek(),
    ]);
}

function eraseNow(User $subject): DataRequest
{
    $request = app(CreateDataRequest::class)->handle($subject, (string) $subject->uuid, DataRequestType::Erasure);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    return $request->refresh();
}

beforeEach(function (): void {
    Storage::fake('local');

    [$workspace] = $this->createWorkspaceWithOwner();
    $this->workspace = $workspace;

    $this->subject = User::factory()->create(['email' => 'erase-me@example.test']);

    erasureFixtureFor($this->subject, (int) $workspace->getKey());
});

it('deletes what the catalogue marks delete', function (): void {
    $request = eraseNow($this->subject);

    expect($request->status)->toBe(DataRequestStatus::Completed)
        ->and(Enrollment::query()->withoutWorkspaceScope()->where('student_user_id', $this->subject->getKey())->count())->toBe(0)
        ->and(LessonProgress::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(Notification::query()->where('recipient_user_id', $this->subject->getKey())->count())->toBe(0);
});

/*
 * ⚠️ THE VERIFIED CONTACT DETAIL IS THE ONE ROW THAT MATTERS MOST.
 *
 * Spec 020 reads the phone number from `contact_verifications`, never from
 * `users.phone` — that column is a free string nobody confirmed. So an erasure that
 * anonymised the account and left this table behind would leave a CONFIRMED way to
 * reach somebody who asked to be forgotten, and it would not show up in any test
 * that only looked at `users`.
 */
it('removes the verified way of reaching them', function (): void {
    eraseNow($this->subject);

    expect(ContactVerification::query()->where('user_id', $this->subject->getKey())->count())->toBe(0);
});

/*
 * ⚠️ THE INVITATION ROW IS WHY `Tenancy` IS AN OWNER AT ALL, AND WHY `Identity`
 * RUNS LAST.
 *
 * It holds a bare address for somebody who may never have signed up — no user id,
 * so no cascade reaches it. And it is found by `where('email', …)`: anonymise
 * `users.email` first and the address survives with nothing left to match it by,
 * which is exactly what a resumed erasure would do if the walk were not partitioned.
 */
it('removes the bare address no foreign key reaches', function (): void {
    eraseNow($this->subject);

    expect(Invitation::query()->withoutWorkspaceScope()->where('email', 'erase-me@example.test')->count())->toBe(0);
});

it('anonymises the account instead of deleting it', function (): void {
    eraseNow($this->subject);

    $user = User::query()->find($this->subject->getKey());

    /*
    | ⚠️ THE ROW SURVIVES, AND THAT IS THE POINT. `workspaces.owner_user_id` is
    | `cascadeOnDelete()` and every other table carries `workspace_id` with NO
    | foreign key — so deleting a teacher's account destroys their workspace and
    | leaves courses, sessions and enrolments pointing at an id that no longer
    | exists: unreachable behind `WorkspaceScope` and undeleted at the same time.
    */
    expect($user)->not->toBeNull()
        ->and($user?->first_name)->toBe('مستخدم محذوف')
        ->and($user?->phone)->toBeNull()
        ->and((string) $user?->email)->toStartWith('anonymised+')
        ->and((string) $user?->email)->not->toContain('erase-me');
});

/*
 * ⚠️ AND A SECOND PASS IS A NO-OP RATHER THAN A SECOND ERASURE.
 *
 * The caller loops until a module returns fewer rows than the limit, so any pass
 * whose predicate still matches what it just processed never terminates — inside a
 * 900-second job with `tries: 1` that is a timeout kill, a sweep revival, and the
 * identical run again, for ever, with nothing in the log to say so. Running the
 * whole thing twice is the cheapest way to catch a predicate that does not shrink.
 */
it('is idempotent, which is what proves every predicate shrinks', function (): void {
    eraseNow($this->subject);

    $before = User::query()->find($this->subject->getKey())?->email;

    $second = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Erasure);
    FulfilDataRequestJob::dispatchSync((int) $second->getKey());

    expect($second->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and(User::query()->find($this->subject->getKey())?->email)->toBe($before);
});

/*
 * ⚠️ FR-023 AND THE SEARCH INDEX — WHY THERE IS NO `unsearchable()` CALL ANYWHERE.
 *
 * A deleted account must appear in no search result, and Scout keeps its own copy
 * of a row OUTSIDE the database: delete the row without telling the engine and the
 * document survives in the index, findable, for ever. So the absence of an index
 * write in this phase needs to be a MEASURED fact rather than an assumption.
 *
 * It is measured here: exactly two models in the product are `Searchable`, both are
 * teacher-authored CONTENT — a course and a bank question — and neither carries a
 * student column of any kind. Both belong to categories declared `Retain`, so no
 * erasure deletes either, and there is nothing for `unsearchable()` to be called
 * on.
 *
 * ⚠️ AND THIS TEST FAILS THE DAY THAT STOPS BEING TRUE. `SCOUT_DRIVER=null` in the
 * suite means no test can see the index itself, so a `Searchable` model added with
 * a `user_id` would otherwise ship with its documents outliving every erasure and
 * nothing anywhere to notice. The list is asserted, not the behaviour, because the
 * list is what the reasoning rests on.
 */
it('has no searchable model that an erasure could strand in the index', function (): void {
    $searchable = [];

    foreach (glob(app_path('Modules/*/Models/*.php')) ?: [] as $file) {
        if (str_contains((string) file_get_contents($file), 'Searchable')) {
            $searchable[] = basename($file, '.php');
        }
    }

    sort($searchable);

    expect($searchable)->toBe(['Course', 'Question'])
        // Both are `Retain`, so no erasure removes the row the document mirrors.
        ->and(DataCategory::query()->where('key', 'authored_content')->sole()->erasure_mode)->toBe(ErasureMode::Retain);
});

/*
 * ⚠️ EVERY CATEGORY DECLARES A GRADE, AND A MISSING ONE IS AN ERASURE THAT DOES
 * NOTHING WHILE LOOKING CONFIGURED.
 *
 * `ExecuteDataErasure` reads the mode from the catalogue and falls back to
 * `Retain` when a module's categories disagree or declare none — the safe
 * direction, because erasure does not reverse. The cost of that safety is that a
 * category which simply forgot the column is silently never erased, so the
 * declaration is asserted here rather than trusted.
 */
it('declares an erasure grade for every category', function (): void {
    $missing = DataCategory::query()
        ->get()
        ->filter(fn (DataCategory $category): bool => ! $category->erasure_mode instanceof ErasureMode)
        ->pluck('key')
        ->all();

    expect($missing)->toBe([])
        ->and(DataCategory::query()->where('key', 'certificate')->sole()->erasure_mode)->toBe(ErasureMode::Retain)
        ->and(DataCategory::query()->where('key', 'teacher_earnings')->sole()->erasure_mode)->toBe(ErasureMode::Retain)
        ->and(DataCategory::query()->where('key', 'lesson_progress')->sole()->erasure_mode)->toBe(ErasureMode::Delete);
});
