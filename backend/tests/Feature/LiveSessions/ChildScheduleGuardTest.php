<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ٠٢٩ · FR-020 · FR-022 — «جدولُ ابني» في الاتّجاهَين.
|
| ⚠️ وليُّ الأمرِ الخالصُ عضوٌ في لا مساحةِ عمل، فسياقُه `null`، و
| `WorkspaceScope::apply()` لا يضيفُ شرطاً حينَ يكونُ المعرَّفُ فارغاً. فاختبارٌ
| مبنيٌّ على وليٍّ خالصٍ وحدَه **يمرُّ على بناءٍ بلا تجاوزِ نطاقٍ فيه إطلاقاً**
| ويُثبِتُ عكسَ ما يدّعيه. الحالةُ الفارقةُ هي وليٌّ **بسياقٍ محلولٍ يشيرُ إلى
| مساحةٍ غيرِ مساحةِ حصّةِ الابن** — وهذا ما يجعلُ النطاقَ يعضُّ فعلاً.
|
| ⚠️ ولا يكفي `assertOk()`: بلا تجاوزِ النطاقِ في التحميلِ المسبَقِ يعودُ الردُّ
| `200` وكلُّ صفٍّ فيه `course: null` و`teacher_name: null`. التوكيدُ على
| **محتوى** الحقولِ هو الفرقُ بينَ حارسٍ يُقاسُ وحارسٍ يُدَّعى.
*/

beforeEach(function (): void {
    // مساحةُ عملِ مدرّسِ الابن.
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->teacherProfile = TeacherProfile::factory()->create([
        'user_id' => $this->teacher->getKey(),
    ]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->teacher->getKey(),
        'title' => 'الفيزياء — الفصل الثالث',
    ]);

    $this->child = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->child->forceFill(['platform_role' => PlatformRole::Student])->save();

    $this->session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->teacherProfile->getKey(),
        'course_id' => $this->course->getKey(),
        'title' => 'المتجهات',
        'starts_at' => CarbonImmutable::now()->addDay(),
        'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->child->getKey(),
    ]);

    $this->guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);
});

/** وليٌّ مأذونٌ بإذنٍ واحدٍ بعينِه. */
function relateGuardian(User $guardian, User $child, GuardianPermission $permission): void
{
    ParentStudentRelation::factory()
        ->withPermissions([$permission])
        ->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $child->getKey(),
            'student_name' => $child->first_name,
        ]);
}

it('serves an authorised guardian whose OWN context resolves to another workspace', function (): void {
    relateGuardian($this->guardian, $this->child, GuardianPermission::Schedule);

    // مساحةٌ ثانيةٌ لا علاقةَ لها بحصّةِ الابن، ثمَّ سياقُ وليِّ الأمرِ **آخرَ ما
    // يُضبَط**: المفرَدُ يجمِّدُ حلَّه عندَ أوّلِ نداء.
    [$foreign, $foreignOwner] = $this->createWorkspaceWithOwner(['slug' => 'foreign-academy']);
    $this->setCurrentWorkspace($foreign, $this->guardian);

    // فحصُ سلامةِ التركيبةِ نفسِها: بلا سياقٍ محلولٍ **مختلف** لا يقيسُ هذا الملفُّ شيئاً.
    expect(app(WorkspaceContext::class)->id())
        ->toBe($foreign->getKey())
        ->and($foreign->getKey())->not->toBe($this->workspace->getKey())
        ->and($foreignOwner->getKey())->not->toBe($this->teacher->getKey());

    Sanctum::actingAs($this->guardian);

    $response = $this->getJson("/api/v1/schedule/children?student={$this->child->uuid}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // ⚠️ المحتوى لا العدد: تحميلٌ مسبَقٌ بلا تجاوزِ نطاقٍ يُعيدُ `null` في كلِّ
    // واحدٍ من هذه الحقولِ ويبقى الردُّ `200`.
    $response
        ->assertJsonPath('data.0.class_session.title', 'المتجهات')
        ->assertJsonPath('data.0.class_session.course.title', 'الفيزياء — الفصل الثالث')
        ->assertJsonPath('data.0.class_session.teacher_name', $this->teacher->name)
        ->assertJsonPath('data.0.class_session.room_closed', false)
        ->assertJsonPath('data.0.class_session.status', 'scheduled');
});

it('sends no field computed about the READER', function (): void {
    relateGuardian($this->guardian, $this->child, GuardianPermission::Schedule);
    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    $response = $this->getJson("/api/v1/schedule/children?student={$this->child->uuid}")->assertOk();

    /*
    | ⚠️ هذا هو الحارسُ الوحيدُ على `T031`. المَورِدُ العامُّ يحسبُ هذه الحقولَ
    | **عن القارئ**: `my_booking = null` عن حصّةٍ ابنُه محجوزٌ فيها، و
    | `join_open = true` دعوةٌ إلى غرفةٍ لا يدخلُها، و`recording.lesson_uuid`
    | رابطٌ يُجيبُه `404`. وبلا هذه التوكيداتِ يستبدلُ قارئٌ لاحقٌ المَورِدَ العامَّ
    | بالضيِّقِ ولا يفشلُ شيء.
    */
    foreach (['join_open', 'my_booking', 'seats', 'recording', 'unlock_open', 'unlock_reason'] as $banned) {
        $response->assertJsonMissingPath("data.0.class_session.{$banned}");
    }
});

it('serves the guardian production actually has — no workspace at all', function (): void {
    relateGuardian($this->guardian, $this->child, GuardianPermission::Schedule);

    // الشكلُ الحقيقيّ: وليُّ أمرٍ لم يكتبْ شيءٌ في مسارِه `last_workspace_id`.
    expect($this->guardian->fresh()?->last_workspace_id)->toBeNull();

    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    $this->getJson("/api/v1/schedule/children?student={$this->child->uuid}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses a guardian holding some OTHER permission', function (): void {
    relateGuardian($this->guardian, $this->child, GuardianPermission::Payments);
    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    $this->getJson("/api/v1/schedule/children?student={$this->child->uuid}")->assertForbidden();
});

it('refuses a named account that is not this guardian\'s child, and says nothing about it', function (): void {
    relateGuardian($this->guardian, $this->child, GuardianPermission::Schedule);
    $stranger = User::factory()->create(['first_name' => 'غريبة']);

    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    $notMine = $this->getJson("/api/v1/schedule/children?student={$stranger->uuid}")->assertForbidden();
    $nobody = $this->getJson('/api/v1/schedule/children?student='.Str::uuid()->toString())
        ->assertForbidden();

    /*
    | ⚠️ ردّانِ لا يفترقان: أيُّ فرقٍ بينهما يخبرُ قارئاً غيرَ مأذونٍ أنّ هذا
    | المعرَّفَ يسمّي شخصاً حقيقيّاً. والمقارنةُ على **الرسالةِ والحالة** لا على
    | الجسدِ الخام: `APP_DEBUG` يُلحِقُ أثرَ نداءٍ يحملُ رقمَ سطرِ **المُختبِر**،
    | فمقارنةُ الجسدَينِ حرفاً بحرفٍ تقيسُ ملفَّ الاختبارِ لا الرد.
    */
    expect($notMine->getStatusCode())->toBe($nobody->getStatusCode())
        ->and($notMine->json('message'))->toBe($nobody->json('message'));

    // ⚠️ `getContent()` يهرّبُ ما ليس ASCII، فتوكيدٌ بإبرةٍ عربيّةٍ فوقَه صادقٌ
    // على الفراغِ مهما تسرّب. يُعادُ الترميزُ بلا تهريب.
    $body = json_encode($notMine->json(), JSON_UNESCAPED_UNICODE);
    expect($body)->not->toContain('غريبة');
});

it('refuses the child\'s OWN teacher — the direction the first principle names', function (): void {
    relateGuardian($this->guardian, $this->child, GuardianPermission::Schedule);

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);

    // مدرّسُ الابنِ يقرأُ سجلَّه في مساحتِه هو — ولا يقرأُ جدولَه عبرَ مدرّسيه
    // الآخرين. الوِلايةُ هي الحارس، لا التسجيل.
    $this->getJson("/api/v1/schedule/children?student={$this->child->uuid}")->assertForbidden();
});

it('refuses a request that names no student at all', function (): void {
    relateGuardian($this->guardian, $this->child, GuardianPermission::Schedule);
    $this->asGuest();
    Sanctum::actingAs($this->guardian);

    $this->getJson('/api/v1/schedule/children')->assertStatus(422);
});
