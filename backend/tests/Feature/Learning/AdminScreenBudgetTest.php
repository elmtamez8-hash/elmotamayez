<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Filament\Pages\AssignStudentToCohort;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| ٠٣٤ · US1 — **كلفةُ شاشةِ الإسنادِ لا تنمو بعددِ الطلاب.**
|
| ⛔ **والحقولُ تُؤكَّدُ حاضرةً كذلك، وهذه نصفُ الاختبارِ لا زينتُه.** اختبارٌ
| يقيسُ الاستعلاماتِ وحدَها **يقرأُ إسقاطَ الضمِّ المُسبَقِ تحسيناً**: الحقلُ
| يغيبُ، والصفحةُ أرخصُ باستعلام، والقائمةُ بلا أسماء — وهو ما شُحِنَ في ستّةِ
| مواضعَ في هذه الشجرةِ باسمِ `->with('relation:id,uuid,name')`.
|
| ⚠️ **وتركيبتانِ بحجمَينِ مختلفَين، لا واحدة.** سقفٌ مطلَقٌ على تركيبةٍ واحدةٍ
| يُمرِّرُ `N+1` كاملاً ما دامَ العددُ صغيراً — والمقياسُ هو **الفرق**، لا الرقم.
|
| ⚠️ **والاسمُ يُقرَأُ من الحمولةِ لا من النموذج.** `users.name` سِمةٌ مشتقّةٌ من
| عمودَين، وتركيبةٌ تسألُ `$enrollment->student->name` تُجيبُ صحيحاً فوقَ شاشةٍ
| تطبعُ فراغاً — فالتوكيدُ هنا على ما **تُصيِّرُه الصفحة**.
|
| ⚠️ **والتحويرُ قِيسَ ثلاثَ مرّاتٍ، وواحدةٌ منها فاجأت:**
|
| | التحوير | ما سقط |
| |---|---|
| | `->with(['student' => fn ($r) => $r->select('id', 'email')])` | **الأسماءُ وحدَها** — وهو عطبُ المواضعِ الستّة بعينِه |
| | استعلامٌ في جسمِ عمودٍ بدلَ الاستعلامِ الفرعيِّ المرتبط | **الكلفةُ وحدَها** (٢٥ مقابل ٨) |
| | **حذفُ `->with(['student'])` كلِّيّاً** | **لا شيء — والاختباران أخضران** |
|
| والثالثةُ ليست ثغرةً في هذا الملفِّ بل قياسٌ عن Filament: هو يضمُّ مُسبَقاً
| علاقةَ كلِّ عمودٍ منقوطٍ (`student.name`) من تلقاءِ نفسِه. فالسطرُ يبقى
| **صريحاً عمداً**: جسمُ الوصفِ يقرأُ `$record->student?->email` — مساراً آخرَ
| غيرَ مسارِ العمود — وسلوكٌ ضمنيٌّ في إطارٍ ليسَ عقداً يُبنى عليه.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
        ]),
    );

    Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
    ]);

    $this->officer = User::factory()->create(['is_super_admin' => true]);
});

/** @return list<string> the names the screen actually rendered */
function enrolStudents(int $count): array
{
    $names = [];

    for ($i = 0; $i < $count; $i++) {
        $student = User::factory()->create(['first_name' => 'طالب', 'last_name' => 'رقم '.$i]);

        Enrollment::query()->create([
            'workspace_id' => test()->workspace->getKey(),
            'course_id' => test()->course->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'purchase',
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $names[] = $student->name;
    }

    return $names;
}

function assignScreenCost(): int
{
    /*
    | ⚠️ **تُحمَّى حتّى تستقرّ، ومرّةٌ واحدةٌ ليست ذلك.** قِيسَ هنا: أوّلُ تصييرٍ
    | كلّفَ **٩** والثاني **٥** — والأربعةُ فرقاً صفوفُ صلاحيّاتٍ وإعداداتٍ
    | تُخبَّأُ بعدَ أوّلِ قراءة. فبلا تحميةٍ يقيسُ هذا الاختبارُ ترتيبَ حالتَيه
    | ويقولُ إنّ الشاشةَ الأكبرَ **أرخص**، وهو عكسُ ما كُتِبَ له. السابقةُ مكتوبةٌ
    | في `QueryBudgetTest` بعدَ ارتعاشةٍ بواحدٍ في ثمانيةِ تشغيلات.
    */
    Livewire::test(AssignStudentToCohort::class)->fillForm(['course' => test()->course->getKey()]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(AssignStudentToCohort::class)->fillForm(['course' => test()->course->getKey()]);

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

it('costs the same for twenty students as for three', function (): void {
    $this->actingAs($this->officer);

    enrolStudents(3);
    $small = assignScreenCost();

    enrolStudents(17);
    $large = assignScreenCost();

    /*
    | ⚠️ **الفرقُ هو المقياس، لا الرقم.** سقفٌ ثابتٌ يُدعى «معقولاً» يُمرِّرُ
    | `N+1` كاملاً في تركيبةٍ صغيرة، ويُحمِّرُ البناءَ على تحسينٍ مشروعٍ في
    | الكبيرة. وصفرٌ بالضبط: لا استعلامَ واحدٌ يجوزُ أن يُضافَ بصفّ.
    */
    expect($large)->toBe($small);
});

it('renders the student names it was made cheap to render', function (): void {
    /*
    | ⛔ النصفُ الذي بلا اختبارٍ يجعلُ العطبَ يُقرَأُ تحسيناً. حذفُ
    | `->with('student')` **يُنقِصُ** الاستعلاماتِ ويُبقي الحالةَ فوقَها خضراءَ،
    | والقائمةُ عندئذٍ أسماؤُها فارغة.
    */
    $this->actingAs($this->officer);

    $names = enrolStudents(3);

    $screen = Livewire::test(AssignStudentToCohort::class)
        ->fillForm(['course' => $this->course->getKey()]);

    foreach ($names as $name) {
        $screen->assertSee($name);
    }
});
