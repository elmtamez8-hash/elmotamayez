<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ٠٢٩ · FR-020 · FR-022 — «نسبةُ حضورِ ابني».
|
| ⚠️ `attendances` مملوكٌ لمساحةِ العمل، ولابنٍ يدرسُ عندَ ثلاثةِ مدرّسينَ سجلُّ
| حضورٍ **واحد**. فالتجاوزُ يُقاسُ بابنٍ له حضورٌ في مساحتَي عملٍ ⇒ الصفّانِ معاً —
| وبلا ذلك يمرُّ الاختبارُ على بناءٍ مقيَّدٍ بمساحةٍ واحدة.
|
| ⚠️ والأعدادُ مختارةٌ لتفرِّقَ بينَ صيغتَي النسبة: `(المجموع − الغياب) / المجموع`
| تُعطي ٨٠، و`(حاضر + متأخّر) / (المجموع − العذر)` تُعطي ٧٥. أعدادٌ متناظرةٌ
| تُمرِّرُ الصيغتَينِ معاً ولا تقولُ أيَّهما نُفِّذَت.
*/

/**
 * حصّةٌ في وقتٍ بعينِه، وصفُّ حضورٍ عليها لهذا الابن.
 *
 * ⚠️ `workspace_id` يُمرَّرُ صراحةً في الطرفَين: `BelongsToWorkspace` يملؤُه من
 * السياقِ المجمَّدِ أيّاً كان، فتُكتَبُ صفوفُ المساحةِ الثانيةِ في الأولى ويصيرُ
 * اختبارُ التجاوزِ اختباراً لمساحةٍ واحدةٍ يمرُّ على أيِّ بناء.
 */
function attendedSession(Workspace $workspace, User $child, AttendanceStatus $status, CarbonImmutable $startsAt): void
{
    $test = test();

    $session = ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $test->profiles[$workspace->getKey()],
        'course_id' => $test->courses[$workspace->getKey()],
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    attendanceRow($workspace, $session, $child, $status);
}

beforeEach(function (): void {
    $this->profiles = [];
    $this->courses = [];

    foreach (['first', 'second'] as $index => $label) {
        [$workspace, $owner] = $this->createWorkspaceWithOwner(['slug' => "academy-{$label}"]);
        $this->setCurrentWorkspace($workspace, $owner);

        $this->profiles[$workspace->getKey()] = TeacherProfile::factory()
            ->create(['user_id' => $owner->getKey()])->getKey();
        $this->courses[$workspace->getKey()] = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
        ])->getKey();

        if ($index === 0) {
            [$this->workspace, $this->teacher] = [$workspace, $owner];
        } else {
            [$this->otherWorkspace, $this->otherTeacher] = [$workspace, $owner];
        }
    }

    $this->child = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $this->guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    $now = CarbonImmutable::now();

    // المساحةُ الأولى: ٣ حضور · ١ تأخّر · ١ غياب.
    attendedSession($this->workspace, $this->child, AttendanceStatus::Present, $now->subDays(3));
    attendedSession($this->workspace, $this->child, AttendanceStatus::Present, $now->subDays(5));
    // ⚠️ حدُّ النافذةِ الأعلى: حصّةٌ اليومَ في الحادية عشرةَ ليلاً. `<= اليوم`
    // يربطُ منتصفَ الليلِ فيُسقِطُها، و`< اليومُ + يوم` يحتسبُها.
    attendedSession($this->workspace, $this->child, AttendanceStatus::Present, $now->startOfDay()->addHours(23));
    attendedSession($this->workspace, $this->child, AttendanceStatus::Late, $now->subDays(2));
    attendedSession($this->workspace, $this->child, AttendanceStatus::Absent, $now->subDays(7));

    // المساحةُ الثانية: ٢ حضور · ١ غياب · ٢ عذر — وهي نصفُ الجواب.
    attendedSession($this->otherWorkspace, $this->child, AttendanceStatus::Present, $now->subDays(4));
    attendedSession($this->otherWorkspace, $this->child, AttendanceStatus::Present, $now->subDays(6));
    attendedSession($this->otherWorkspace, $this->child, AttendanceStatus::Absent, $now->subDays(8));
    attendedSession($this->otherWorkspace, $this->child, AttendanceStatus::Excused, $now->subDays(9));
    attendedSession($this->otherWorkspace, $this->child, AttendanceStatus::Excused, $now->subDays(10));

    // خارجَ النافذةِ الافتراضيّةِ تماماً — لا يُحتسَب.
    attendedSession($this->workspace, $this->child, AttendanceStatus::Absent, $now->subDays(40));

    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Attendance])
        ->create([
            'guardian_user_id' => $this->guardian->getKey(),
            'student_user_id' => $this->child->getKey(),
            'student_name' => $this->child->first_name,
        ]);
});

it('counts the four states across every workspace the child studies in', function (): void {
    // السياقُ المحلولُ يشيرُ إلى مساحةٍ **واحدةٍ** من الاثنتَين: لو عضَّ النطاقُ
    // لعادَ نصفُ الجواب.
    $this->setCurrentWorkspace($this->workspace, $this->guardian);
    expect(app(WorkspaceContext::class)->id())->toBe($this->workspace->getKey());

    Sanctum::actingAs($this->guardian);

    $this->getJson("/api/v1/attendance/children/summary?student={$this->child->uuid}")
        ->assertOk()
        ->assertJsonPath('data.window_days', 30)
        ->assertJsonPath('data.present', 5)
        ->assertJsonPath('data.late', 1)
        ->assertJsonPath('data.absent', 2)
        ->assertJsonPath('data.excused', 2)
        ->assertJsonPath('data.total', 10)
        // ⚠️ ٨٠ لا ٧٥: العذرُ يُحتسَبُ حضوراً، والغيابُ وحدَه ينقص.
        ->assertJsonPath('data.rate_pct', 80);
});

it('narrows to the window it was asked for', function (): void {
    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    // ثلاثةُ أيّامٍ: الحضورانِ في اليومِ نفسِه وقبلَ يومَين، والتأخّرُ قبلَ يومَين.
    $this->getJson("/api/v1/attendance/children/summary?student={$this->child->uuid}&days=3")
        ->assertOk()
        ->assertJsonPath('data.window_days', 3)
        ->assertJsonPath('data.present', 1)
        ->assertJsonPath('data.late', 1)
        ->assertJsonPath('data.absent', 0)
        ->assertJsonPath('data.total', 2);
});

it('answers null rather than zero when there is nothing to average', function (): void {
    $orphan = User::factory()->create(['platform_role' => PlatformRole::Student]);
    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Attendance])
        ->create([
            'guardian_user_id' => $this->guardian->getKey(),
            'student_user_id' => $orphan->getKey(),
            'student_name' => $orphan->first_name,
        ]);

    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    // «لا حصصَ بعد» ليست «صفرُ حضور»: الثانيةُ جملةٌ عن طالبٍ يتغيّب.
    $this->getJson("/api/v1/attendance/children/summary?student={$orphan->uuid}")
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.rate_pct', null);
});

it('refuses a guardian holding some OTHER permission', function (): void {
    $other = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Schedule])
        ->create([
            'guardian_user_id' => $other->getKey(),
            'student_user_id' => $this->child->getKey(),
            'student_name' => $this->child->first_name,
        ]);

    $this->asGuest();
    Sanctum::actingAs($other);

    $this->getJson("/api/v1/attendance/children/summary?student={$this->child->uuid}")
        ->assertForbidden();
});

it('refuses a named account that is not this guardian\'s child, and says nothing about it', function (): void {
    $stranger = User::factory()->create(['first_name' => 'غريبة']);

    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    $notMine = $this->getJson("/api/v1/attendance/children/summary?student={$stranger->uuid}")
        ->assertForbidden();
    $nobody = $this->getJson('/api/v1/attendance/children/summary?student='.Str::uuid()->toString())
        ->assertForbidden();

    /*
    | ⚠️ الرسالةُ والحالةُ لا الجسدُ الخام: `APP_DEBUG` يُلحِقُ أثرَ نداءٍ يحملُ
    | رقمَ سطرِ المُختبِر، فمقارنةُ الجسدَينِ تقيسُ ملفَّ الاختبارِ لا الرد.
    | و`getContent()` يهرّبُ ما ليس ASCII، فإبرةٌ عربيّةٌ فوقَه صادقةٌ على الفراغ.
    */
    expect($notMine->getStatusCode())->toBe($nobody->getStatusCode())
        ->and($notMine->json('message'))->toBe($nobody->json('message'));

    expect(json_encode($notMine->json(), JSON_UNESCAPED_UNICODE))->not->toContain('غريبة');
});

it('refuses the child\'s OWN teacher — the direction the first principle names', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);

    $this->getJson("/api/v1/attendance/children/summary?student={$this->child->uuid}")
        ->assertForbidden();
});

it('refuses a request that names no student at all', function (): void {
    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    $this->getJson('/api/v1/attendance/children/summary')->assertStatus(422);
});
