<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| مَن في الغرفة — الوصلُ الذي بدونه كلُّ صفٍّ uuid خام.
|
| التذكرةُ تحملُ `uuid` هويّةً ولا تحملُ الاسمَ أبداً (`FR-006`): الهويّةُ يبثّها
| المزوّدُ لكلِّ من في الغرفة، واسمٌ داخلَ حمولةِ مزوّدٍ اسمٌ لم نعد نتحكّمُ به. فهذا
| هو الطريقُ الآخر: أسماءٌ ووجوهٌ وشاراتٌ من مسارِنا نحن، لقارئٍ تحقّقنا منه.
|
| ⚠️ وهو **ليس** كشفَ الحضور. `ATTENDANCE_VIEW` يقول «‏لك أن تقرأ من حضر وكم بقي
| ولماذا غُيِّرت علامته»؛ وهذا يملكه كلُّ صاحبِ مقعد — فأيُّ حقلٍ من الكشفِ هنا
| يسلّمُ كلَّ طالبٍ سجلَّ زملائه.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);
});

/** Drop the cached resolution so the next request resolves as the caller would. */
function forgetRosterWorkspaceContext(): void
{
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
}

/** A seat, taken by a student the way production makes one: no membership. */
function rosterSeatHolder(?string $firstName = null): User
{
    $student = User::factory()->create($firstName === null ? [] : ['first_name' => $firstName]);

    app(WorkspaceContext::class)->set(test()->workspace);

    SessionBooking::create([
        'workspace_id' => test()->workspace->getKey(),
        'class_session_id' => test()->session->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => 'booked',
        'is_billable' => true,
        'booked_at' => now(),
    ]);

    return $student;
}

it('answers a seat holder with names, roles and badges instead of uuids', function (): void {
    $student = rosterSeatHolder('سلمى');

    Badge::factory()->create(['key' => 'streak', 'name_ar' => 'مواظبة']);
    BadgeAward::create([
        'user_id' => $student->getKey(),
        'badge_key' => 'streak',
        'awarded_at' => now(),
    ]);

    Sanctum::actingAs($student);
    forgetRosterWorkspaceContext();

    $response = $this->getJson('/api/v1/class-sessions/'.$this->session->uuid.'/participants')
        ->assertOk();

    $roster = collect($response->json('data'))->keyBy('uuid');

    expect($roster)->toHaveKey($student->uuid)
        ->and($roster[$student->uuid]['name'])->toBe($student->name)
        ->and($roster[$student->uuid]['role'])->toBe('student')
        ->and($roster[$student->uuid]['badges'])->toHaveCount(1)
        ->and($roster[$student->uuid]['badges'][0]['name_ar'])->toBe('مواظبة')
        // The teacher is in the room too, and the screen says which one they are.
        ->and($roster[$this->owner->uuid]['role'])->toBe('host')
        ->and($roster[$this->owner->uuid]['badges'])->toBe([]);
});

it('carries nothing from the register, which needs a permission this reader lacks', function (): void {
    $student = rosterSeatHolder();

    Sanctum::actingAs($student);
    forgetRosterWorkspaceContext();

    $row = $this->getJson('/api/v1/class-sessions/'.$this->session->uuid.'/participants')
        ->assertOk()
        ->json('data.0');

    /*
     * An allowlist claims an ABSENCE, so it is asserted as one: checking that the
     * four expected keys are present proves nothing about what else came along
     * for the ride, and every one of these is a field `ATTENDANCE_VIEW` guards.
     */
    expect(array_keys($row))->toEqualCanonicalizing(['uuid', 'name', 'role', 'avatar_url', 'badges'])
        ->and($row)->not->toHaveKeys([
            'status', 'stay_seconds', 'attendance_status', 'note', 'email', 'phone',
            /*
             * ⚠️ AND `is_removed` IS ABSENT RATHER THAN FALSE. Whether the teacher
             * put somebody out of the lesson is a moderation fact about that
             * person; the host needs it to offer them back, and a key that is
             * always there tells every classmate the question was asked. The list
             * above is a canonical comparison, so this line is belt and braces —
             * kept because the failure it names is the one worth reading.
             */
            'is_removed',
        ]);
});

it('tells the host who was put out, so they can be let back in', function (): void {
    // The allow direction. A deny-only assertion passes just as well against a
    // build where nobody is ever told — including the one person who must be.
    $student = rosterSeatHolder();

    Attendance::create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => 'absent',
        'removed_at' => now(),
    ]);

    Sanctum::actingAs($this->owner);

    $roster = collect(
        $this->getJson('/api/v1/class-sessions/'.$this->session->uuid.'/participants')
            ->assertOk()
            ->json('data')
    )->keyBy('uuid');

    expect($roster[$student->uuid]['is_removed'])->toBeTrue()
        ->and($roster[$this->owner->uuid]['is_removed'])->toBeFalse();
});

it('refuses somebody with no seat and no host permission', function (): void {
    // The control. Without it every case above would also pass against a route
    // that answered the whole class roll to anyone who asked.
    $stranger = User::factory()->create();

    Sanctum::actingAs($stranger);
    forgetRosterWorkspaceContext();

    $this->getJson('/api/v1/class-sessions/'.$this->session->uuid.'/participants')
        ->assertForbidden();
});

it('costs the same number of queries for two participants as for eight', function (): void {
    // ⚠️ A Resource runs once per row, so a badge lookup inside one is an N+1 by
    // construction — the defect `ClassSessionResource` already cost this
    // repository once. Measured at two sizes, because a single size cannot tell
    // a flat read from a growing one.
    $reader = rosterSeatHolder();

    // ⚠️ THE FIRST READER HOLDS A BADGE TOO, and that is not decoration. The
    // catalogue read is skipped entirely when nobody has an award, so a small
    // case with no badges and a large one with badges differ by a CONSTANT — and
    // the test would fail over a read that is perfectly flat.
    Badge::factory()->create(['key' => 'streak', 'name_ar' => 'مواظبة']);
    BadgeAward::create([
        'user_id' => $reader->getKey(),
        'badge_key' => 'streak',
        'awarded_at' => now(),
    ]);

    Sanctum::actingAs($reader);
    forgetRosterWorkspaceContext();

    $url = '/api/v1/class-sessions/'.$this->session->uuid.'/participants';

    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson($url)->assertOk();
        $queries = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    /*
     * ⚠️ PRIMED FIRST. The route asks the policy whether the reader is the host
     * before it builds the payload, and spatie's permission set is loaded once
     * per process — so the FIRST request of any test pays for a cache warm-up
     * that has nothing to do with the number of participants. Measured cold, the
     * small case cost more than the large one and the assertion failed on a read
     * that is perfectly flat.
     */
    $count($url);

    $small = $count($url);

    for ($i = 0; $i < 6; $i++) {
        $extra = rosterSeatHolder();
        BadgeAward::create([
            'user_id' => $extra->getKey(),
            'badge_key' => 'streak',
            'awarded_at' => now(),
        ]);
    }

    Sanctum::actingAs($reader);
    forgetRosterWorkspaceContext();

    expect($count($url))->toBe($small);
});
