<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\JoinWaitlist;
use App\Modules\Learning\Filament\Pages\CourseWaitlist;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CourseWaitlistEntry;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\CohortPricingGap;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| ٠٣٤ · T050 — شاشةُ الدَّور، مُشغَّلةً لا مقروءة.
|
| ⛔ **وهذه هي الحالةُ التي لا يراها أيُّ اختبارٍ آخر.** `pint` و`phpstan` يمرّانِ
| على صفحةِ Filament كاملةً بلا تشغيلِ سطرٍ واحدٍ منها: حقنُ `$rowLoop` في مُغلِفِ
| `state()`، وحسابُ إزاحةِ الصفحة، و`whereRaw('1 = 0')` قبلَ اختيارِ الكورس،
| وزرُّ الدعوة — كلُّها تُقاسُ هنا أو على الإنتاج. وقاعدةُ هذه الشجرةِ مكتوبة:
| «وُجِدَ بالمشي على المنتَج، لا باختبار».
|
| ⛔ **ومساحتا عملٍ وموظَّفٌ يملكُ إحداهما، ويقرأُ دَورَ الأخرى.**
| `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id` لكلِّ مستخدمٍ بمن
| فيهم موظَّفُ المنصّة — وتركيبةٌ بموظَّفٍ لا مساحةَ له تمرُّ على بناءٍ بلا تجاوزِ
| نطاقٍ واحد.
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
            'title' => 'الكيمياء العضويّة',
        ]),
    );

    $this->cohort = Cohort::factory()->full()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة الأحد',
        'created_by' => $this->awayTeacher->getKey(),
    ]);

    // الموظَّفُ يملكُ مساحةً — وليست التي يعملُ فيها. السطرُ الذي يُشغِّلُ العطبَ إن كان.
    $this->officer = User::factory()->create([
        'is_super_admin' => true,
        'last_workspace_id' => $this->home->getKey(),
    ]);
});

/** يصطفُّ طالبٌ جديدٌ في دَورِ الكورس، ويُردُّ صفُّه. */
function queueOne(string $name): CourseWaitlistEntry
{
    $student = User::factory()->create(['first_name' => $name, 'last_name' => 'ع']);

    return app(JoinWaitlist::class)->handle($student, test()->course);
}

it('shows nothing at all before a course is chosen', function (): void {
    // ⚠️ الحاجزُ في الاستعلامِ لا في ظهورِ الجدول: Filament يبني الجدولَ عندَ
    // التركيب، فشرطٌ في `visible()` يُخفي الصفحةَ وقد نُفِّذَ المسحُ بالفعل.
    queueOne('سارة');

    $this->actingAs($this->officer);

    Livewire::test(CourseWaitlist::class)->assertCanNotSeeTableRecords(
        CourseWaitlistEntry::query()->withoutWorkspaceScope()->get(),
    );
});

it('reads another workspace\'s queue in order, and numbers it from the page', function (): void {
    Carbon::setTestNow('2026-09-14 09:00:00');
    $first = queueOne('سارة');
    $second = queueOne('ليلى');

    Carbon::setTestNow('2026-09-14 09:00:05');
    $third = queueOne('نور');
    Carbon::setTestNow();

    $this->actingAs($this->officer);

    Livewire::test(CourseWaitlist::class)
        ->fillForm(['course' => $this->course->getKey()])
        // ⚠️ **بالترتيب**: `assertCanSeeTableRecords` تُؤكِّدُ الترتيبَ حينَ
        // `inOrder: true` — والأوّلانِ في الثانيةِ نفسِها، فيفصلُهما المعرِّفُ.
        ->assertCanSeeTableRecords([$first, $second, $third], inOrder: true);
});

it('invites as many as there are seats, and tells them', function (): void {
    $first = queueOne('سارة');
    queueOne('ليلى');

    // مقعدٌ واحدٌ فُتِح.
    $this->cohort->forceFill(['capacity' => 2, 'members_count' => 1])->save();

    $this->actingAs($this->officer);

    Livewire::test(CourseWaitlist::class)
        ->fillForm(['course' => $this->course->getKey()])
        // ⚠️ `callTableAction`، لا `callAction`: الزرُّ إجراءٌ في **رأسِ الجدولِ**
        // لا في رأسِ الصفحة، و`callAction` تبحثُ عن مِنهاجٍ على المكوّنِ نفسِه.
        ->callTableAction('invite', null, ['cohort' => (string) $this->cohort->uuid])
        ->assertHasNoTableActionErrors();

    expect(CourseWaitlistEntry::query()->withoutWorkspaceScope()
        ->whereNotNull('invited_at')->pluck('id')->all())
        ->toBe([$first->getKey()]);

    expect(Notification::query()
        ->where('recipient_user_id', $first->student_user_id)
        ->where('type', 'waitlist_invited')
        ->count())->toBe(1);
});

it('is closed to a tenant role, however senior', function (): void {
    /*
    | ⚠️ **شاشةُ Filament لا تستدعي سياسةً إطلاقاً**، وقَبولُ اللوحةِ نفسِها
    | «مديرُ منصّةٍ أو أيُّ صفٍّ في `platform_staff`». وهذه الشاشةُ تحملُ أسماءَ
    | طلابٍ عبرَ كلِّ المساحات، فحراستُها ليست شكليّة.
    */
    $teacher = $this->addWorkspaceMember($this->away, Roles::TEACHER);

    $this->actingAs($teacher);
    app(WorkspaceContext::class)->set($this->away);

    expect(CourseWaitlist::canAccess())->toBeFalse();

    $this->actingAs($this->officer);

    expect(CourseWaitlist::canAccess())->toBeTrue();
});

it('warns the officer which group cannot be bought before a single invitation goes out', function (): void {
    /*
    | ⛔ **٠٣٦ · FR-020 — الإخبارُ هو الحلّ، لا المنع.** الدعوةُ إسنادٌ إداريٌّ
    | فلا تقرأُ الباقات (FR-004): منعُها يتركُ مقعداً فارغاً بسببِ خانةِ سعرٍ
    | عندَ المنصّة. ودعوةٌ صامتةٌ تُوصِلُ المدعوَّ إلى شاشةٍ لا يشتري منها شيئاً،
    | **وصفُّه مختومٌ فلا يعودُ مرشَّحاً أبداً**. فالمواصفةُ تقولُ بحرفِها:
    | «والإخبارُ وحدَه يُسقِطُ الاثنَين» — يُرسِلُ المسؤولُ عالماً أو ينتظر.
    |
    | ⚠️ **والمجموعةُ المُسعَّرةُ بلا علامةٍ هي نصفُ القياس.** علامةٌ تظهرُ على
    | كلِّ صفٍّ لا تقولُ شيئاً، وتُقرَأُ زينةً بعدَ أسبوع.
    */
    $priced = Cohort::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة الثلاثاء',
        'created_by' => $this->awayTeacher->getKey(),
    ]);

    Plan::factory()->group()->forCohort((string) $priced->uuid)->create([
        'workspace_id' => $this->away->getKey(),
    ]);

    // ⚠️ باقةٌ كُتِبَت ولم تُسعَّرْ — وهي الحالةُ الوحيدةُ التي لا عملَ فيها
    // للمدرّس، فلا يجوزُ أن تُقرَأَ «لا توجد باقة».
    $waiting = Cohort::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => 'مجموعة الخميس',
        'created_by' => $this->awayTeacher->getKey(),
    ]);

    Plan::factory()->unpriced()->forCohort((string) $waiting->uuid)->create([
        'workspace_id' => $this->away->getKey(),
    ]);

    $this->actingAs($this->officer);

    /*
    | ⚠️ **يُقاسُ ما يُرسَمُ في المُنتقي، لا ما يردُّه مِنهاجٌ خاصّ.** الخيارُ هو
    | كلُّ ما يراه المسؤولُ قبلَ الضغط، وتأكيدٌ على قيمةٍ داخليّةٍ يمرُّ فوقَ
    | نموذجٍ يحسبُها ثمّ لا يعرضُها.
    */
    $options = Livewire::test(CourseWaitlist::class)
        ->fillForm(['course' => $this->course->getKey()])
        ->instance()
        ->cohortOptions();

    expect($options[(string) $waiting->uuid] ?? '')
        ->toContain(CohortPricingGap::AwaitingPricing->label())
        // ⛔ والمُسعَّرةُ بلا علامة — انظرْ أعلاه.
        ->and($options[(string) $priced->uuid] ?? 'x')->not->toContain('⚠️')
        // ⚠️ والمجموعةُ بلا باقةٍ أصلاً تقولُ جملةً أخرى: الفرقُ بينَهما «دورُ
        // مَن هو الآن» — المدرّسِ أم المنصّة — ودمجُهما يُرسِلُ أحدَهما ينتظرُ
        // قراراً لا ينتظرُه أحد.
        ->and($options[(string) $this->cohort->uuid] ?? null)->toBeNull();
});
