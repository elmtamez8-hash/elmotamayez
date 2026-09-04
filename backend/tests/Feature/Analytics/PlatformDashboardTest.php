<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Analytics\Filament\Widgets\GrowthChartWidget;
use App\Modules\Analytics\Filament\Widgets\MoneyPulseWidget;
use App\Modules\Analytics\Filament\Widgets\PlatformPulseWidget;
use App\Modules\Analytics\Filament\Widgets\RegionSpreadWidget;
use App\Modules\Analytics\Filament\Widgets\RevenueChartWidget;
use App\Modules\Analytics\Filament\Widgets\StudentMoneyWidget;
use App\Modules\Analytics\Filament\Widgets\StudentPerformanceWidget;
use App\Modules\Analytics\Filament\Widgets\TopTeachersWidget;
use App\Modules\Analytics\Filament\Widgets\TrustPulseWidget;
use App\Modules\Analytics\Filament\Widgets\ViolationsWidget;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Livewire\Livewire;

/*
| لوحةُ المؤشّرات — القراءةُ الحيّةُ للمنصّة.
|
| ⚠️ **مساحتانِ دائماً، ومديرٌ تُشيرُ `last_workspace_id` عندَه إلى إحداهما.**
| `WorkspaceContext::id()` يرتدُّ إلى ذلك العمودِ لكلِّ مستخدمٍ بمن فيهم مديرُ
| المنصّة، فقراءةٌ بقيَ عليها النطاقُ تعرضُ أرقامَ مساحةٍ واحدةٍ على أنّها أرقامُ
| المنصّة — **وتمرُّ خضراءَ في تجهيزةٍ بمساحةٍ واحدة**. سطرٌ واحدٌ في ٠٢٤ (إسنادُ
| مساحةٍ لموظّفِ المال) كشفَ خمسَ طبقاتٍ من هذا العطلِ بعينِه.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->alpha] = $this->createWorkspaceWithOwner(['name' => 'أكاديميّة ألف']);
    [$this->beta] = $this->createWorkspaceWithOwner(['name' => 'أكاديميّة باء']);

    $this->admin = User::factory()->create(['is_super_admin' => true]);

    // ⚠️ هذا السطرُ هو ما يُسلِّحُ التجهيزةَ كلَّها: بدونِه سياقُ المديرِ فارغٌ
    // والنطاقُ خاملٌ، فيمرُّ استعلامٌ منطاقٌ كأنّه غيرُ منطاق.
    $this->admin->forceFill(['last_workspace_id' => $this->alpha->getKey()])->save();

    $this->actingAs($this->admin);

    armWorkspaceFallback();
    Filament::setCurrentPanel('admin');
});

/** طالبٌ في مساحةٍ بعينِها، مع كورسٍ وتسجيل. */
function insightStudent(Workspace $workspace, string $first = 'طالب'): User
{
    $test = test();

    $student = User::factory()->create(['platform_role' => 'student', 'first_name' => $first]);

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $student): void {
        $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

        Enrollment::query()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'active',
            'progress_pct' => 40,
            'enrolled_at' => now(),
        ]);
    });

    $test->actingAs($test->admin);
    armWorkspaceFallback();

    return $student;
}

/**
 * ⛔ **`forget()` لا يصلحُ هنا، وهو ما جعلَ نقضَ هذا الملفِّ يمرُّ أخضرَ.**
 *
 * `WorkspaceContext::forget()` يكتبُ `resolvedId = null` **و`resolved = true`** —
 * أي يُجمِّدُ المفردةَ على الفراغ. و`WorkspaceScope::apply()` لا يُضيفُ شرطاً حينَ
 * يكونُ المعرِّفُ فارغاً، فالنطاقُ يصيرُ **خاملاً**: حذفُ
 * `withoutWorkspaceScope()` من الويدجتِ لا يُغيِّرُ رقماً واحداً، والتوكيدُ يمرُّ
 * فوقَ العطلِ الذي وُضِعَ ليمسكَه.
 *
 * قِيسَ 2026-09-04: بحذفِ التخطّي بقيَ الاختبارُ أخضرَ، والمِسبارُ أظهرَ
 * `ctx=null` و`scoped == bare == 3`.
 *
 * إسقاطُ المفردةِ من الحاويةِ يُعيدُ الحلَّ من الصفرِ — فيرتدُّ إلى
 * `users.last_workspace_id` كما يفعلُ الإنتاجُ تماماً، ويصيرُ النطاقُ حيّاً.
 */
function armWorkspaceFallback(): void
{
    app()->forgetInstance(WorkspaceContext::class);
}

/**
 * ويدجتاتُ المنصّةِ التسعة — قائمةٌ واحدةٌ يقرؤها التوكيدانِ معاً.
 *
 * ⚠️ نسختانِ من قائمةٍ واحدةٍ تفترقانِ عندَ أوّلِ ويدجتٍ يُضاف، فيبقى الجديدُ
 * محروساً في أحدِ الاختبارَينِ ومكشوفاً في الآخر.
 *
 * @return list<class-string<Widget>>
 */
function platformWidgets(): array
{
    return [
        PlatformPulseWidget::class,
        MoneyPulseWidget::class,
        TrustPulseWidget::class,
        RevenueChartWidget::class,
        GrowthChartWidget::class,
        StudentPerformanceWidget::class,
        StudentMoneyWidget::class,
        TopTeachersWidget::class,
        ViolationsWidget::class,
        RegionSpreadWidget::class,
    ];
}

it('shows the teacher none of the platform widgets on the dashboard they share', function (): void {
    $owner = $this->alpha->owner;
    $this->actingAs($owner);
    $this->setCurrentWorkspace($this->alpha, $owner);

    /*
    | ⛔ هذا هو الحارسُ كلُّه. الويدجتاتُ مسجَّلةٌ على لوحةِ `/admin` المشتركةِ التي
    | يصلُها كلُّ مدرّس، و`Page::filterVisibleWidgets()` ينادي `canView()` على كلٍّ
    | منها قبلَ التصيير — فحذفُ `PlatformWideWidget` من صنفٍ واحدٍ يُسلِّمُ كلَّ
    | مدرّسٍ أرقامَ منافسيه، بلا خطأٍ في أيِّ مكان.
    */
    foreach (platformWidgets() as $widget) {
        expect($widget::canView())->toBeFalse();
    }
});

it('counts both academies, not the one the reader falls back into', function (): void {
    insightStudent($this->alpha);
    insightStudent($this->beta);
    insightStudent($this->beta);

    $rendered = Livewire::test(PlatformPulseWidget::class)->assertSuccessful()->html();

    /*
    | ⛔ التوكيدُ على **الجملةِ كاملةً**، لا على الرقمِ وحدَه.
    |
    | `toContain('3')` كان يمرُّ فوقَ الحذفِ التامِّ لـ`withoutWorkspaceScope()`:
    | الرقمُ ٣ موجودٌ في الصفحةِ على أيِّ حال — في عدَّةِ الطلابِ غيرِ المنطاقةِ
    | وفي معرِّفِ Livewire — فالتوكيدُ كان يقيسُ وجودَ محرفٍ لا صحّةَ قراءة. قِيسَ
    | بالنقضِ قبلَ الشحن: بحذفِ التخطّي بقيَ أخضرَ.
    |
    | ومع النطاقِ يقرأُ العدّادُ ١ — تسجيلَ «ألف» وحدَه، وهي المساحةُ التي يرتدُّ
    | إليها `last_workspace_id` عندَ المدير.
    */
    expect($rendered)->toContain('3 منهم يدرسون الآن')
        ->and($rendered)->not->toContain('1 منهم يدرسون الآن');
});

it('never adds two currencies into one number', function (): void {
    foreach ([['QAR', 48000], ['USD', 4999]] as [$currency, $amount]) {
        $order = Order::query()->create([
            'workspace_id' => $this->beta->getKey(),
            'user_id' => User::factory()->create()->getKey(),
            'amount_minor' => $amount,
            'currency' => $currency,
            'status' => 'approved',
        ]);

        PaymentTransaction::query()->create([
            'workspace_id' => $this->beta->getKey(),
            'order_id' => $order->getKey(),
            'provider' => 'manual',
            'amount_minor' => $amount,
            'currency' => $currency,
            'status' => 'captured',
            'reference' => 'TRX-'.$currency,
        ]);
    }

    $rendered = Livewire::test(MoneyPulseWidget::class)->assertSuccessful()->html();

    /*
    | ⚠️ التوكيدُ على العملتَينِ معاً **وعلى غيابِ المجموع**: ‏٥٢٩٫٩٩ رقمٌ صحيحُ
    | الحساب ولا معنى له، وهو ما كان سيُعرَضُ بثقةٍ على شاشةِ إدارة.
    */
    expect($rendered)->toContain('480.00 ر.ق')
        ->and($rendered)->toContain('49.99 دولار')
        ->and($rendered)->not->toContain('529.99')
        /*
        | ⚠️ وعدّادانِ منفصلان، لا سطرٌ واحدٌ يضمُّهما. «‏99.98 USD · 480.00 QAR»
        | شُحِنَ وأُبلِغَ عنه «غيرُ مفهوم»: رقمانِ لعملتَينِ في خانةٍ واحدةٍ يُقرآنِ
        | مجموعاً، وهو الوهمُ الذي مُنِعَ الجمعُ لأجلِه.
        */
        ->and($rendered)->not->toContain('ر.ق ·')
        ->and($rendered)->toContain('المحصَّل — ريال قطري');
});

it('says there are no ratings yet instead of printing a zero', function (): void {
    $rendered = Livewire::test(TrustPulseWidget::class)->assertSuccessful()->html();

    // ⚠️ «٠٫٠٠ / 5» عن منصّةٍ لم يُقيَّمْ فيها أحدٌ حكمٌ كاذبٌ على المدرّسين.
    expect($rendered)->toContain('لا تقييمات بعد')
        ->and($rendered)->not->toContain('0.00 / 5');
});

it('ranks students across academies and keeps the never-examined out of the bottom list', function (): void {
    $strong = insightStudent($this->alpha, 'مجتهد');
    $weak = insightStudent($this->beta, 'متعثّر');
    $untested = insightStudent($this->beta, 'جديد');

    foreach ([[$strong, 90], [$weak, 30]] as [$student, $score]) {
        $enrollment = Enrollment::query()->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())->sole();

        $exam = Exam::factory()->create(['workspace_id' => $enrollment->workspace_id]);

        Attempt::query()->create([
            'workspace_id' => $enrollment->workspace_id,
            'exam_id' => $exam->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'graded',
            'is_practice' => false,
            'score' => $score,
            'max_score' => 100,
            // ⚠️ `random_seed` ليس فارغاً في الهجرة: ترتيبُ الأسئلةِ لهذه الورقةِ
            // مُثبَتٌ عندَ البدءِ لا مُعادٌ عندَ كلِّ فتح.
            'random_seed' => 1234,
        ]);
    }

    // ⚠️ `loadTable()`: جدولُ Filament مؤجَّلُ التحميل، فبدونِه لا يُنفَّذُ
    // مُنسِّقُ عمودٍ واحدٍ ويمرُّ التوكيدُ فوقَ العطلِ نفسِه.
    Livewire::test(StudentPerformanceWidget::class)
        ->loadTable()
        ->assertSuccessful()
        /*
        | ⚠️ `inOrder` على **مفاتيحِ الصفوف** لا `assertSeeInOrder` على النصّ:
        | لقطةُ Livewire هي JSON، والعربيّةُ فيها مهروبةٌ `\u` — فإبرةٌ عربيّةٌ
        | لا تُطابِقُ شيئاً مهما كانت الحمولة. عائلةُ `getContent()` نفسُها التي
        | جعلَت ملفَّ تسريبٍ كاملاً يمرُّ خضراءَ فوقَ استجابةٍ تُسرِّبُ كلَّ شيء.
        */
        ->assertCanSeeTableRecords([$strong, $weak, $untested])
        ->assertCanSeeTableRecords([$strong, $weak], inOrder: true);

    /*
    | ⚠️ «الأدنى» يشترطُ ورقةً مصحَّحة. بدونِ الشرطِ يتصدّرُ الطالبُ الذي لم يجلسْ
    | لامتحانٍ قطُّ قائمةَ المتأخّرين — وهو عكسُ ما تُبحَثُ عنه.
    */
    Livewire::test(StudentPerformanceWidget::class)
        ->filterTable('rank', 'bottom')
        ->loadTable()
        ->assertCanSeeTableRecords([$weak, $strong])
        ->assertCanNotSeeTableRecords([$untested]);
});

it('separates the biggest payers from the students in arrears', function (): void {
    $payer = insightStudent($this->alpha, 'دافع');
    $debtor = insightStudent($this->beta, 'مدين');

    $order = Order::query()->create([
        'workspace_id' => $this->alpha->getKey(),
        'user_id' => $payer->getKey(),
        'amount_minor' => 48000,
        'currency' => 'QAR',
        'status' => 'approved',
    ]);

    PaymentTransaction::query()->create([
        'workspace_id' => $this->alpha->getKey(),
        'order_id' => $order->getKey(),
        'provider' => 'manual',
        'amount_minor' => 48000,
        'currency' => 'QAR',
        'status' => 'captured',
        'reference' => 'TRX-PAYER',
    ]);

    CreditBalance::factory()->create([
        'workspace_id' => $this->beta->getKey(),
        'student_user_id' => $debtor->getKey(),
        'remaining_credits' => -3,
        'negative_since' => now()->subDays(20),
    ]);

    Livewire::test(StudentMoneyWidget::class)
        ->loadTable()
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$payer]);

    Livewire::test(StudentMoneyWidget::class)
        ->filterTable('lens', 'defaulters')
        ->loadTable()
        ->assertCanSeeTableRecords([$debtor])
        ->assertCanNotSeeTableRecords([$payer]);
});

it('counts a teacher students inside the filtered subject, not their whole roll', function (): void {
    $physics = Subject::query()->firstOrCreate(
        ['slug' => 'physics-insight'],
        ['name_ar' => 'الفيزياء', 'is_active' => true, 'sort_order' => 90],
    );

    $teacher = User::factory()->create(['platform_role' => 'teacher']);

    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->alpha->getKey(),
        'user_id' => $teacher->getKey(),
    ]);

    $profile->subjects()->attach($physics->getKey());

    // كورسانِ لمدرّسٍ واحد: واحدٌ في الفيزياء وواحدٌ في مادّةٍ أخرى.
    foreach ([[$physics->getKey(), 'فيزيائيّ'], [null, 'آخر']] as [$subjectId, $name]) {
        $course = Course::factory()->create([
            'workspace_id' => $this->alpha->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'subject_id' => $subjectId ?? Subject::query()->where('id', '!=', $physics->getKey())->value('id'),
        ]);

        Enrollment::query()->create([
            'workspace_id' => $this->alpha->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => User::factory()->create(['platform_role' => 'student', 'first_name' => $name])->getKey(),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
    }

    Livewire::test(TopTeachersWidget::class)
        ->loadTable()
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$profile]);

    /*
    | ⚠️ اثنانِ بلا مرشِّحٍ وواحدٌ تحتَ «الفيزياء». عدَدٌ ثابتٌ بجانبِ مرشِّحٍ يجعلُ
    | مدرّسَ ثلاثِ موادَّ يتصدّرُ قائمةَ الفيزياءِ بطلابِ الرياضيّات.
    | ⚠️ والتحقّقُ من القيمةِ نفسِها: `assertCanSeeTableRecords` يمرُّ على عدَدٍ
    | خاطئٍ تماماً كما يمرُّ على صحيح.
    */
    $narrowed = Livewire::test(TopTeachersWidget::class)
        ->filterTable('subject', $physics->getKey())
        ->loadTable();

    $rows = $narrowed->instance()->getTable()->getRecords();

    expect((int) $rows->first()?->getAttribute('students'))->toBe(1);
});

it('shows a complaint raised in an academy the reader does not belong to', function (): void {
    $profile = TeacherProfile::factory()->create(['workspace_id' => $this->beta->getKey()]);

    $complaint = Complaint::query()->create([
        'workspace_id' => $this->beta->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'reported_by' => User::factory()->create()->getKey(),
        'reason' => 'تأخّر عن الحصص مرّتين.',
        'status' => 'pending',
    ]);

    Livewire::test(ViolationsWidget::class)
        ->loadTable()
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$complaint])
        ->assertSee('قيد البتّ');
});

/**
 * ⚠️ الشاشةُ الرئيسيّةُ هي المكان، ولا صفحةَ ثانيةً تحتَها.
 *
 * التسجيلُ في `->widgets([])` هو ما يجعلُ اللوحةَ تعرضُ شيئاً؛ صنفٌ مبنيٌّ ولا
 * يُسجَّلُه أحدٌ ملفٌّ لا شاشة — وهو ما شُحِنَ أوّلَ مرّةٍ خلفَ صفحةٍ منفصلةٍ لا
 * يجدُها أحد. هذا التوكيدُ يسقطُ في اللحظةِ التي يُحذَفُ فيها سطرُ التسجيل.
 */
it('registers every platform widget on the dashboard itself', function (): void {
    $shared = Filament::getPanel('admin')->getWidgets();

    foreach (platformWidgets() as $widget) {
        expect($shared)->toContain($widget);
    }
});
