<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Actions\WithdrawTransferRequest;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| ٠٣٤ · FR-006 · FR-008 — **الطالبُ يُبلَّغُ بمجموعتِه، وبأنّ طلبَه سقطَ ولماذا.**
|
| ⛔ **كلاهما كانَ غائباً تماماً، والقياسُ هو ما قالَه**: مستمِعُ
| `CohortMembershipOpened` الوحيدُ في الشجرةِ يُحرِّرُ المقاعدَ ويحجزُها — لا
| إشعارَ في أيِّ موضع — وسببُ الإسقاطِ يُكتَبُ في `decision_reason` منذُ ٠٢١
| **وقارئاه مساران إداريّانِ للمدرّس**. فالطالبُ يُنقَلُ بينَ الغرفِ ويختفي طلبُه
| بلا كلمة.
|
| ⚠️ **والحالةُ السالبةُ هنا ليست زينةً**: `CohortMembershipOpened` يقعُ على
| أربعةِ مسارات، وانتقالٌ وُوفِقَ عليه له إشعارُه الخاصُّ بجملةٍ أدقّ — فبلا
| ترشيحٍ على نوعِ الحدثِ يقرأُ الطالبُ رسالتَينِ عن حركةٍ واحدة.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
            'title' => 'الكيمياء',
        ]),
    );

    // ⛔ ٠٣٦ · FR-003: a group no live price reaches cannot be joined at all, and
    // one case here has the student join of their own accord.
    groupPriceFor($this->course);

    $this->saturday = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة السبت',
        'created_by' => $this->teacher->getKey(),
    ]);

    $this->student = User::factory()->create();

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'purchase',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $this->officer = User::factory()->create(['is_super_admin' => true]);
});

/** @return list<string> */
function typesSentTo(User $student): array
{
    return Notification::query()
        ->where('recipient_user_id', $student->getKey())
        ->pluck('type')
        ->map(static fn (mixed $type): string => $type instanceof NotificationType ? $type->value : (string) $type)
        ->all();
}

it('tells the student which group they are in and when it meets', function (): void {
    app(MoveMember::class)->handle($this->saturday, $this->student, $this->officer);

    $row = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->get()
        ->firstWhere(fn (Notification $n): bool => ($n->type instanceof NotificationType
            ? $n->type->value
            : (string) $n->type) === NotificationType::CohortAssigned->value);

    expect($row)->not->toBeNull()
        ->and($row->body)->toContain('مجموعة السبت')
        ->and($row->body)->toContain('الكيمياء')
        /*
        | ⚠️ **«لم تُعلَنْ مواعيدُها بعد» تُقالُ صراحة** (سابقةُ ٠٢٧ · FR-029أ).
        | سطرٌ محذوفٌ يُقرَأُ عُطلاً، فيُحدِّثُ الطالبُ الصفحةَ ويسألُ إن كانَ
        | إسنادُه وقعَ أصلاً — والمجموعةُ الجديدةُ بلا جدولٍ هي الحالُ العاديّة.
        */
        ->and($row->body)->toContain('لم تُعلَن مواعيدها');
});

it('tells the guardian on the schedule consent which group their child was placed in', function (): void {
    /*
    | A placement is a decided change to the child's timetable — the family's
    | week moves with it. Without `subject:` on the request the fan-out reaches
    | nobody, which is what the payments-only guardian's zero also guards.
    */
    $schedule = guardianOf($this->student, [GuardianPermission::Schedule]);
    $paymentsOnly = guardianOf($this->student, [GuardianPermission::Payments]);

    app(MoveMember::class)->handle($this->saturday, $this->student, $this->officer);

    expect(typesSentTo($schedule))->toContain(NotificationType::CohortAssigned->value)
        ->and(typesSentTo($paymentsOnly))->not->toContain(NotificationType::CohortAssigned->value);
});

it('says nothing extra when the student joined on their own', function (): void {
    /*
    | ⚠️ الضابطُ الذي يُثبِتُ الترشيحَ على نوعِ الحدث. انضمامُ الطالبِ بنفسِه
    | نتيجةٌ يراها على الشاشةِ التي ضغطَ فيها، ورسالةٌ عنه رسالةٌ زائدة.
    */
    app(JoinCohort::class)->handle($this->saturday, $this->student);

    expect(typesSentTo($this->student))->not->toContain(NotificationType::CohortAssigned->value);
});

it('tells the student their pending request was dropped, and by whom', function (): void {
    $sunday = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة الأحد',
        'created_by' => $this->teacher->getKey(),
    ]);

    CohortTransferRequest::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'to_cohort_id' => $sunday->getKey(),
        'student_reason' => 'الأحد أنسب',
    ]);

    app(MoveMember::class)->handle(
        $this->saturday,
        $this->student,
        $this->officer,
        dropNote: 'أسندتك إدارة المنصّة إلى مجموعة مباشرةً، فأُغلق طلب انتقالك السابق.',
    );

    expect(typesSentTo($this->student))
        ->toContain(NotificationType::CohortTransferRequestDropped->value)
        // ⚠️ وليسَ رفضاً: «لم يوافقْ مدرّسك» يُرسِلُ الطالبَ يسألُ مدرّساً لم
        // يقرأْ طلبَه أصلاً.
        ->not->toContain(NotificationType::CohortTransferRejected->value);
});

it('does not tell somebody what they themselves just did', function (): void {
    /*
    | ⚠️ الشرطُ هو **الفاعلُ** لا نصُّ السبب: جملةٌ تُقارَنُ حرفيّاً تنكسرُ عندَ
    | أوّلِ تحريرٍ لها. ورسالةٌ تُخبِرُ الطالبَ بما فعلَه قبلَ ثانيةٍ هي أوّلُ
    | خطوةٍ نحوَ كتمِ القناةِ التي تحملُ تنبيهَ الغياب.
    */
    $sunday = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
    ]);

    $request = CohortTransferRequest::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'to_cohort_id' => $sunday->getKey(),
    ])->refresh();

    app(WithdrawTransferRequest::class)->handle($request, $this->student);

    expect(typesSentTo($this->student))
        ->not->toContain(NotificationType::CohortTransferRequestDropped->value);
});
