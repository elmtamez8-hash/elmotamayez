<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Contracts\CohortDirectory;
use Illuminate\Support\Carbon;

/*
| ⛔ **مُنتقي المجموعةِ يقولُ متى تجتمع.**
|
| الموظَّفُ يُسنِدُ طالباً إلى مجموعةٍ من إجراءِ «اعتمد» على الطلب، ومن شاشةِ
| الإسنادِ كذلك — وكلاهما يقرأُ `assignableOptionsFor()`. وكانَ الخيارُ اسماً
| وعددَ مقاعدَ ولا شيءَ عن الموعد، فالقرارُ يُتَّخَذُ على عُرفِ التسميةِ
| («الأحد ٦م») لا على البيانات: مجموعةٌ اسمُها «المجموعة الثانية» لا تقولُ شيئاً.
|
| ⚠️ **والموعدُ يُقرَأُ جُملةً واحدة.** الخياراتُ تُبنى داخلَ `->options()`، فسؤالٌ
| لكلِّ صفٍّ هو N+1 على شاشةٍ تُفتَحُ لكلِّ طلب — و`schedulePreviewFor()` جمليٌّ
| بالتعريف.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
});

/*
| ⚠️ **`pickerCohort` لا `makeCohort`.** مساعِدُ Pest دالّةٌ **عامّة**،
| و`IndividualCohortIndexTest` يُعرِّفُ `makeCohort` بتوقيعٍ آخر — والملفّانِ
| يُحمَّلانِ في العاملِ نفسِه عندَ `--parallel` أو عندَ تسميةِ المجلَّد، فيقعُ
| `Cannot redeclare`. القاعدةُ في CLAUDE.md: سَمِّ المساعِدَ باسمِ ما يخصُّه
| هذا الملفَّ، لا باسمِ الاسمِ العامِّ في المجال.
*/
function pickerCohort(string $name, int $capacity = 8): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => test()->course->getKey(),
        'created_by' => test()->owner->getKey(),
        'name' => $name,
        'capacity' => $capacity,
        'members_count' => 0,
        'status' => 'open',
    ]);
}

it('carries the group\'s meeting time into the option, beside its seats', function (): void {
    $cohort = pickerCohort('المجموعة الثانية');

    // الأحدُ السادسةُ مساءً — مرّتانِ، فهي الفترةُ المتكرّرة.
    foreach (['2026-09-20 18:00:00', '2026-09-27 18:00:00'] as $at) {
        ClassSession::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $this->teacher->getKey(),
            'course_id' => $this->course->getKey(),
            'cohort_id' => $cohort->getKey(),
            'starts_at' => Carbon::parse($at),
        ]);
    }

    $label = app(CohortDirectory::class)->assignableOptionsFor((int) $this->course->getKey())[(string) $cohort->uuid];

    expect($label)->toContain('المجموعة الثانية')
        ->and($label)->toContain('18:00')
        // والمقاعدُ تبقى: هذه إضافةٌ لا استبدال.
        ->and($label)->toContain('مقعداً');
});

it('says so when the group has no sessions yet, rather than leaving a gap', function (): void {
    /*
    | ⚠️ **الفراغُ في مكانِ الموعدِ يُقرَأُ خطأً في الشاشةِ لا حقيقةً عن المجموعة**،
    | ومجموعةٌ بلا حصصٍ قرارٌ مختلفٌ عن مجموعةٍ لها موعد. وعقدُ
    | `schedulePreviewFor()` يقولُ صراحةً إنّ الفارغَ قائمةٌ فارغةٌ لا مفتاحٌ غائب،
    | ويُوجِبُ على المنادي أن يكتبَ الجملة.
    */
    $cohort = pickerCohort('مجموعة بلا حصص');

    $label = app(CohortDirectory::class)->assignableOptionsFor((int) $this->course->getKey())[(string) $cohort->uuid];

    expect($label)->toContain('لم تُجدول حصص بعد');
});
