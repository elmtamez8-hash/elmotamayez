<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Filament\Pages\AssignStudentToCohort;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/*
| ٠٣٤ · US1 — **الإدارةُ تُسنِدُ طالباً إلى مجموعة.**
|
| ⛔ **مساحتا عملٍ وموظَّفٌ يملكُ إحداهما، ويُسنِدُ في الأخرى.** وهذا ليسَ زخرفاً:
| `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id` لكلِّ مستخدمٍ بمن
| فيهم موظَّفُ المنصّة — وسطرٌ واحدٌ كهذا هو ما كشفَ طبقاتِ ٠٢٤ الخمسَ كلَّها،
| وهو ما كانَ يمنعُ `ExecuteTeacherOffboarding` من إتمامِ خروجٍ لموظَّفٍ تختلفُ
| مساحتُه. وكلُّ تركيبةٍ بموظَّفٍ **لا مساحةَ له** — وهي كلُّ تركيبةٍ قائمةٍ
| اليوم — **تمرُّ على البناءِ المعطوبِ كلِّه**.
|
| ⚠️ **والطالبُ يُبنى بلا شيء.** `User::factory()` وحدَها: لا بذرة، ولا عضويّةَ
| مساحة، ولا `last_workspace_id`. الطالبُ الحقيقيُّ عضوٌ في لا مساحة، فتركيبةٌ
| تمنحُه واحدةً تُعطيه باباً لا يُعطيه الإنتاجُ أبداً.
|
| ⚠️ **والتحويرُ قِيسَ، ولم يُفتَرَضْ** — والقياسُ خالفَ ما كُتِبَ هنا أوّلاً:
| حذفُ `withoutWorkspaceScope()` من `enrollments()` أسقطَ **حالتَي القائمة**
| (الإسنادَ والمُرشِّح) وحدَهما؛ وحذفُه من قراءةِ المجموعةِ في `assign()` أسقطَ
| **الإسنادَ وإسقاطَ الطلب**. حالةُ «قبلَ اختيارِ الكورس» وحالةُ «مجموعةٌ من
| كورسٍ آخر» بقيَتا خضراوَينِ في التحويرَين، لأنّهما تقيسانِ آليّتَينِ
| أُخرَيَين — وهذا ما يجعلُهما ضابطَين.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);
    [$this->away, $this->awayTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية سلمى']);

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->away,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $this->away->getKey(),
            'created_by' => $this->awayTeacher->getKey(),
            'title' => 'الفيزياء الحديثة',
        ]),
    );

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة السبت',
        'created_by' => $this->awayTeacher->getKey(),
    ]);

    $this->student = User::factory()->create(['first_name' => 'سارة', 'last_name' => 'ع']);

    $this->enrollment = Enrollment::query()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'purchase',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    /*
    | ⛔ **السطرُ الذي يُشغِّلُ العطبَ إن كان.** الموظَّفُ يملكُ مساحةً — وهذا
    | حالٌ عاديّ: مالكُ المنصّةِ يدرّسُ أيضاً — **وليست** المساحةَ التي يعملُ
    | فيها. بلا هذا السطرِ يكونُ سياقُه `null`، و`WorkspaceScope` عندئذٍ لا
    | يُضيفُ شرطاً أصلاً، فيمرُّ بناءٌ بلا تجاوزِ نطاقٍ واحد.
    */
    $this->officer = User::factory()->create([
        'is_super_admin' => true,
        'last_workspace_id' => $this->home->getKey(),
    ]);
});

it('assigns a student in a workspace the officer does not belong to', function (): void {
    $this->actingAs($this->officer);

    Livewire::test(AssignStudentToCohort::class)
        ->fillForm(['course' => $this->course->getKey()])
        ->assertCanSeeTableRecords([$this->enrollment])
        ->callTableAction('assign', $this->enrollment, ['cohort' => $this->cohort->getKey()])
        ->assertHasNoTableActionErrors();

    $membership = CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())->get();

    expect($membership)->toHaveCount(1)
        ->and((int) $membership->first()->cohort_id)->toBe((int) $this->cohort->getKey())
        // The seat is claimed by the writer, once.
        ->and((int) $this->cohort->refresh()->members_count)->toBe(1);
});

it('names the officer and the moment in the history the student reads', function (): void {
    $this->actingAs($this->officer);

    Livewire::test(AssignStudentToCohort::class)
        ->fillForm(['course' => $this->course->getKey()])
        ->callTableAction('assign', $this->enrollment, [
            'cohort' => $this->cohort->getKey(),
            'reason' => 'طلب وليّ الأمر السبت',
        ]);

    $row = CohortMembershipEvent::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())->sole();

    /*
    | ⚠️ `assigned` لا `transferred` (FR-005). الطالبُ يقرأُ سطرَه بنفسِه،
    | و«نُقِلتَ» على عضويّةٍ **أولى** جملةٌ عن حدثٍ لم يقعْ — ولا `joined`، فهي
    | تُسنِدُ الفعلَ إلى الطالبِ الذي لم يفعلْ شيئاً.
    */
    expect($row->event)->toBe(CohortMembershipEvent::ASSIGNED)
        ->and((int) $row->actor_user_id)->toBe((int) $this->officer->getKey())
        ->and($row->reason)->toBe('طلب وليّ الأمر السبت')
        ->and($row->created_at)->not->toBeNull();
});

it('drops a pending transfer request with a sentence that names the right actor', function (): void {
    /*
    | ⚠️ **«نقلك المدرّس» جملةٌ كاذبةٌ على هذا المسار** (FR-008). الطلبُ الذي
    | يختفي بلا جملةٍ يُقرَأُ عُطلاً ويُعادُ إرسالُه (٠٢١ · FR-028ح)، وجملةٌ
    | تُسمّي فاعلاً لم يفعلْ أسوأُ من الصمت.
    */
    $other = Cohort::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة الأحد',
        'created_by' => $this->awayTeacher->getKey(),
    ]);

    // ⚠️ `status` و`pending_slot` غيرُ قابلَينِ للإسنادِ الجماعيّ — قيمتاهما من
    // افتراضِ العمود، فالمثيلُ الذي يردُّه `create()` يحملُ `null` لهما.
    $request = CohortTransferRequest::query()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'from_cohort_id' => null,
        'to_cohort_id' => $other->getKey(),
        'student_reason' => 'الأحد أنسب',
    ])->refresh();

    $this->actingAs($this->officer);

    Livewire::test(AssignStudentToCohort::class)
        ->fillForm(['course' => $this->course->getKey()])
        ->callTableAction('assign', $this->enrollment, ['cohort' => $this->cohort->getKey()]);

    $request->refresh();

    expect($request->status)->toBe(CohortTransferRequest::DROPPED)
        ->and($request->decision_reason)->toContain('إدارة المنصّة')
        ->and($request->decision_reason)->not->toContain('المدرّس');
});

it('hides whoever is already in a group, and shows them the moment the filter is lifted', function (): void {
    $placed = User::factory()->create(['first_name' => 'ليان', 'last_name' => 'م']);

    $placedEnrollment = Enrollment::query()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $placed->getKey(),
        'source' => 'purchase',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    CohortMembership::query()->create([
        'workspace_id' => $this->away->getKey(),
        'cohort_id' => $this->cohort->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $placed->getKey(),
        'joined_at' => now(),
    ]);

    $this->actingAs($this->officer);

    $screen = Livewire::test(AssignStudentToCohort::class)
        ->fillForm(['course' => $this->course->getKey()]);

    // The default IS the query the requirement names.
    $screen->assertCanSeeTableRecords([$this->enrollment])
        ->assertCanNotSeeTableRecords([$placedEnrollment]);

    /*
    | ⚠️ والضابطُ المقابل: إعادةُ الإسنادِ نقلٌ مشروعٌ تملكُه الإدارةُ كما تملكُ
    | الأوّل. شاشةٌ لا طريقَ فيها إلى المسنَدِ سلفاً تُرسِلُ الموظَّفَ إلى قاعدةِ
    | البيانات.
    */
    $screen->filterTable('unassigned', false)
        ->assertCanSeeTableRecords([$this->enrollment, $placedEnrollment]);
});

it('shows nothing at all before a course is chosen', function (): void {
    /*
    | ⛔ الحاجزُ في الاستعلامِ لا في ظهورِ الجدول. «كلُّ الكورسات» افتراضاً مسحٌ
    | لكلِّ تسجيلٍ على المنصّةِ مع استعلامٍ فرعيٍّ لكلِّ صفّ — وهو كذلك يُسقِطُ
    | العمودَ الأوّلَ من كلِّ فهرسٍ مركَّبٍ يبدأُ بالمساحة.
    */
    $this->actingAs($this->officer);

    Livewire::test(AssignStudentToCohort::class)
        ->assertCanNotSeeTableRecords([$this->enrollment]);
});

it('refuses a group that belongs to another course, however the request is shaped', function (): void {
    /*
    | ⚠️ المُنتقي يعرِضُ مجموعاتِ الكورسِ وحدَه — **وهذا ليسَ الحارس**. الطلبُ
    | يُكتَبُ كما يُنقَر، و«أسنِدْ» على معرِّفِ مجموعةٍ من كورسٍ آخرَ يضعُ الطالبَ
    | في غرفةٍ لا يملكُ فيها تسجيلاً.
    */
    $foreign = app(WorkspaceContext::class)->forWorkspace(
        $this->home,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $this->home->getKey(),
            'created_by' => $this->homeTeacher->getKey(),
        ]),
    );

    $foreignCohort = Cohort::factory()->create([
        'workspace_id' => $this->home->getKey(),
        'course_id' => $foreign->getKey(),
        'created_by' => $this->homeTeacher->getKey(),
    ]);

    $this->actingAs($this->officer);

    Livewire::test(AssignStudentToCohort::class)
        ->fillForm(['course' => $this->course->getKey()])
        ->callTableAction('assign', $this->enrollment, ['cohort' => $foreignCohort->getKey()]);

    expect(CohortMembership::query()->withoutWorkspaceScope()->count())->toBe(0);
});
