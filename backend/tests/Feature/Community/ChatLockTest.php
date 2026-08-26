<?php

declare(strict_types=1);

use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| «أغلق النقاش» — الصمتُ أثناء الشرح، ثمّ الأسئلة.
|
| ⚠️ والمدرّسُ مُستثنى من قفلِه، وهذا ليس تسهيلاً. مَن يُغلقُ النقاشَ ثمّ يجدُ حقلَه
| مُعطَّلاً لا يستطيعُ أن يجيبَ آخرَ سؤالٍ على الشاشة، ولا أن يقولَ لماذا أغلق —
| فالقفلُ يصيرُ باباً بلا مقبضٍ من الجهتَين.
|
| ⚠️ ولا يُطبَّقُ على محادثةٍ خاصّة: إسكاتُ شخصٍ بعينه هو **الإيقاف**، مُعلَنٌ على
| مستوى المساحة ومُسجَّلٌ وقابلٌ للاعتراض. طريقان لإسكاتِ إنسان، أحدُهما بلا سجلّ،
| هو الطريقُ الذي لا يُراجعه أحد.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // The seat is written directly: `BookSeat` also spends a credit, and this
    // file is about who may write in the room, not about what a session costs.
    SessionBooking::create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => 'booked',
        'is_billable' => true,
        'booked_at' => now(),
    ]);
});

/** The room under the session, as whoever is signed in sees it. */
function openRoom(object $test): array
{
    return $test->getJson('/api/v1/class-sessions/'.$test->session->uuid.'/chat')
        ->assertOk()
        ->json();
}

it('shuts the student out of writing and lets the teacher keep answering', function (): void {
    Sanctum::actingAs($this->student);
    $room = openRoom($this);

    // ⚠️ ASSERTED BEFORE THE LOCK AS WELL AS AFTER IT. A refusal measured only
    // after would be green against a build where a student can never write in a
    // room at all — the seat guard firing, not the lock.
    expect($room['is_locked'])->toBeFalse();
    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'سؤال قبل القفل'])
        ->assertCreated();

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])
        ->assertOk()
        ->assertJsonPath('is_locked', true);

    // The teacher keeps writing, which is the half a naive lock gets wrong.
    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'انتبهوا للشرح'])
        ->assertCreated();

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'سؤال بعد القفل'])
        ->assertForbidden();

    // And reading never stopped: a closed discussion is not a closed room.
    expect(openRoom($this)['is_locked'])->toBeTrue();
    $this->getJson("/api/v1/conversations/{$room['uuid']}/messages")->assertOk();
});

it('opens it again, and the student writes', function (): void {
    Sanctum::actingAs($this->owner);
    $room = openRoom($this);

    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])->assertOk();
    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => false])
        ->assertOk()
        ->assertJsonPath('is_locked', false);

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'سؤالي الآن'])
        ->assertCreated();
});

it('does not move the timestamp on a second press', function (): void {
    // The stamp is the record of WHEN the discussion was closed. A second press
    // is not a second decision, and re-stamping it would move a moderation fact
    // to whenever somebody last touched the button.
    Sanctum::actingAs($this->owner);
    $room = openRoom($this);

    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])->assertOk();
    $first = Conversation::query()
        ->withoutGlobalScopes()->where('uuid', $room['uuid'])->value('locked_at');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])->assertOk();

    expect(Conversation::query()
        ->withoutGlobalScopes()->where('uuid', $room['uuid'])->value('locked_at'))
        ->toEqual($first);

    CarbonImmutable::setTestNow();
});

it('refuses the lock to the student it would silence', function (): void {
    // The control. Without it every case above would also pass on a build where
    // anyone in the room can close it for everybody else.
    Sanctum::actingAs($this->owner);
    $room = openRoom($this);

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])
        ->assertForbidden();
});

/*
| ١٠ · «إخراج» يُخرِجُ من الصورةِ ومن النقاشِ معاً.
|
| المقعدُ يبقى عمداً — إلغاؤه مصادرةٌ لحصّةٍ دفعَ ثمنَها بسببِ دقيقةِ سلوك — فلا
| يستطيعُ أيُّ فحصٍ للمقعدِ أن يرى هذا. والقفلُ يُسكِتُ الفصلَ كلَّه ليصلَ إلى واحد.
| فبقيَ للمدرّسِ أداةٌ واحدة: **إيقافٌ على مستوى المساحة كلِّها** — مُسجَّلٌ وقابلٌ
| للاعتراضِ ويشملُ كلَّ محادثةٍ معه إلى الأبد. وزنٌ خاطئٌ لساعةٍ واحدة.
*/
it('stops a removed student writing in the room, and only in that room', function (): void {
    Sanctum::actingAs($this->student);
    $room = openRoom($this);

    // ⚠️ BEFORE THE REMOVAL AS WELL AS AFTER IT: a refusal measured only
    // afterwards is green against a build where this student could never write
    // in a room at all — the seat guard firing, not the removal.
    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'سؤال قبل الإخراج'])
        ->assertCreated();

    Attendance::create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => 'present',
        'auto_status' => 'present',
        'source' => 'automatic',
        'stay_seconds' => 60,
        'removed_at' => now(),
    ]);

    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'نفس الكلام بالكتابة'])
        ->assertForbidden();

    // And READING never stops: the removal is about the hour, not about the
    // record of it. Same rule the lock already keeps.
    $this->getJson("/api/v1/conversations/{$room['uuid']}/messages")->assertOk();

    // ⚠️ AND NOT A BAN. The private thread with the same teacher is untouched —
    // a workspace-wide silence is a different decision with its own record and
    // its own appeal, and folding one into the other is how the audited one
    // stops being the only way.
    $private = $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
    ])->assertCreated()->json();

    $this->postJson("/api/v1/conversations/{$private['uuid']}/messages", ['body' => 'أستاذ، ممكن أفهم؟'])
        ->assertCreated();
});

it('never stops the teacher writing in a room they cleared', function (): void {
    // The host has an attendance row of their own — `CloseClassSession` judges
    // delivery from it — and «أخرِج الجميع» could stamp one on a second teacher.
    // Moderation is the exemption, exactly as it is for the lock.
    Attendance::create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->owner->getKey(),
        'status' => 'present',
        'auto_status' => 'present',
        'source' => 'automatic',
        'stay_seconds' => 600,
        'removed_at' => now(),
    ]);

    Sanctum::actingAs($this->owner);
    $room = openRoom($this);

    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'نكمل الشرح'])
        ->assertCreated();
});
