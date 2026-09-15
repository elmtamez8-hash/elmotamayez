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

    /*
    | الأحدُ السادسةُ مساءً **بتوقيتِ المنصّة** — مرّتانِ، فهي الفترةُ المتكرّرة.
    |
    | ⛔ والمنطقةُ مذكورةٌ صراحةً، وهي نصفُ الاختبار. `config('app.timezone')` هو
    | `UTC` و`sessions.timezone` هو `Asia/Qatar`، فوقتٌ مكتوبٌ بلا منطقةٍ هنا
    | يعني UTC — وكانَ المُنتقي يطبعُه كما هو، فتُعلَنُ حصّةُ السادسةِ «15:00».
    | قِيسَ على الإنتاجِ في ٢٠٢٦-٠٩-١٥: مجموعةٌ سمّاها مدرّسُها «السبت ٥م» كانت
    | تُعلِنُ «السبت 14:00».
    |
    | ⚠️ **و`->utc()` لازمةٌ لا زينة.** إيلوكوِنت يكتبُ ساعةَ الحائطِ الخاصّةَ
    | بكائنِ Carbon كما هي، بلا تحويل — فوقتٌ بمنطقةِ قطرٍ بلا `->utc()` يُخزَّنُ
    | «18:00» ويُقرَأُ UTC، فيصيرُ الحدثُ نفسُه ثلاثَ ساعاتٍ متأخّراً في القاعدة.
    | والإنتاجُ يكتبُ UTC دائماً: `ScheduleSessionData` تستدعي `->utc()` بنفسِها.
    */
    foreach (['2026-09-20 18:00:00', '2026-09-27 18:00:00'] as $at) {
        ClassSession::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $this->teacher->getKey(),
            'course_id' => $this->course->getKey(),
            'cohort_id' => $cohort->getKey(),
            'starts_at' => Carbon::parse($at, 'Asia/Qatar')->utc(),
        ]);
    }

    $label = app(CohortDirectory::class)->assignableOptionsFor((int) $this->course->getKey())[(string) $cohort->uuid];

    expect($label)->toContain('المجموعة الثانية')
        ->and($label)->toContain('الأحد 18:00')
        /*
        | والمقاعدُ تبقى: هذه إضافةٌ لا استبدال.
        |
        | ⚠️ **وكانَ هذا التوكيدُ `toContain('مقعداً')` فثبّتَ خطأً**: «٨ مقعداً»
        | ليست عربيّة. سقطَ يومَ صارَ العددُ يُوافَقُ عبرَ `CountedNoun`، وهو
        | السقوطُ الصحيح — توكيدٌ يحرسُ صيغةً خاطئةً يمنعُ إصلاحَها.
        */
        ->and($label)->toContain('٨ مقاعد متبقّية');
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

/*
| ⛔ واليومُ هو النصفُ الأخطر، لا الساعة.
|
| حصّةٌ في الواحدةِ بعدَ منتصفِ الليلِ بتوقيتِ قطر هي العاشرةُ مساءَ **اليومِ
| السابق** بتوقيتِ UTC. فتنسيقٌ بلا تحويلٍ لا يخطئُ الساعةَ وحدَها — يخطئُ اسمَ
| اليوم، فتُعلَنُ مجموعةُ الأحدِ «السبت».
|
| ⚠️ والساعةُ رقمٌ قد يشكُّ فيه قارئ؛ واليومُ يُقرأُ حقيقةً فيُبنى عليه. ولهذا
| الحالةُ مستقلّةٌ عن التي قبلَها: تجهيزةٌ في وسطِ النهارِ تعبرُ التحويلَ بلا أن
| تلمسَ هذا الشقَّ إطلاقاً.
*/
it('names the weekday the students meet on, not the one UTC happens to be in', function (): void {
    $cohort = pickerCohort('مجموعة الفجر');

    // الأحدُ الواحدةُ صباحاً بتوقيتِ قطر = السبتُ العاشرةُ مساءً بتوقيت UTC.
    foreach (['2026-09-20 01:00:00', '2026-09-27 01:00:00'] as $at) {
        ClassSession::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $this->teacher->getKey(),
            'course_id' => $this->course->getKey(),
            'cohort_id' => $cohort->getKey(),
            'starts_at' => Carbon::parse($at, 'Asia/Qatar')->utc(),
        ]);
    }

    $label = app(CohortDirectory::class)->assignableOptionsFor((int) $this->course->getKey())[(string) $cohort->uuid];

    expect($label)->toContain('الأحد 01:00')
        ->and($label)->not->toContain('السبت');
});
