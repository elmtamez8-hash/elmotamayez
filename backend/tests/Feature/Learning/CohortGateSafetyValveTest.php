<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Support\LessonAccess;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| ٠٣٤ · FR-015 — **المنهجُ مفتوحٌ لمن لا مجموعةَ له، والجملةُ فوقَه تقولُ مَن
| يُسنِد.** وكانَ هذا الملفُّ يحرسُ العكسَ (٠٢١ · FR-028أ)، فانقلبَ معَ الشرطِ
| الذي كانَ يحرسُه.
|
| ⚠️ **وتعديلُ اختبارٍ حارسٍ في الطلبِ الذي يُغيِّرُ ما يحرسُه هو الشكلُ الذي
| يمرُّ منه عطبٌ حقيقيٌّ باسمِ «تحديثِ التوكيدات»** — فكلُّ تغييرٍ هنا مكتوبٌ
| سببُه بجانبِه، والمحذوفُ مكتوبٌ لماذا لم تعُدْ نتيجتُه قابلةً للإنتاج.
|
| ⚠️ **وT034 قالت «ثلاثٌ تنقلبُ واثنتانِ تُحذَفان»، والواقعُ ثلاثٌ تنقلبُ وواحدةٌ
| تُحذَفُ وواحدةٌ تبقى.** «كورسٌ بلا مجموعاتٍ أصلاً» تبقى لأنّها **الضابط**:
| بدونَها يمرُّ بناءٌ فتحَ الكورسَ بآليّةٍ أخرى تماماً. والعددُ في المهمّةِ وصفٌ،
| والتوكيدُ في الملفِّ هو الحُكم.
|
| ⚠️ **وكلُّ حالةِ فتحٍ هنا تحملُ ضابطاً إيجابيّاً** (`SEQUENCE` حاضرة). توكيدُ
| «لا يحملُ `no_cohort`» وحدَه يمرُّ فوقَ بناءٍ أعادَ تسميةَ الثابتِ، **وفوقَ
| بناءٍ فتحَ الكورسَ كلَّه** — والثاني هو ما يُسلِّمُ المنهجَ لمن لم يُتِمَّ شيئاً.
|
| ⚠️ **ولا يوجدُ اليومَ تحويرٌ يُسقِطُ هذه الحالات**: الطريقةُ المكتوبةُ سابقاً
| هنا («احذِفْ فرعَ `joinableCohortsExist()` من `CohortGate::locks()`») تصفُ
| دالّةً حُذِفَت. التحويرُ الآن هو إعادةُ فرعِ `NO_COHORT` إلى `LessonGate`:
| تسقطُ الحالاتُ الثلاثُ الأولى معاً.
*/

/** Every lock code the curriculum payload carries, in order. */
function lockCodes(array $payload): array
{
    $codes = [];

    foreach ($payload['sections'] as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                if ($lesson['lock'] !== null) {
                    $codes[] = $lesson['lock']['code'];
                }
            }
        }
    }

    return $codes;
}

function addCohort(array $tree, array $attributes = []): Cohort
{
    /*
    | ⛔ ٠٣٦ · FR-003 — `joinableCohortsExist()` NOW ASKS THE PRICE TOO, and this
    | file's whole subject is what that answer does to the curriculum. A group
    | with no plan behind it makes the valve answer `false` for a reason none of
    | these cases is about; the one case that IS about it says so itself.
    |
    | Written on every call rather than once: a second workspace-wide plan is a
    | second live price, which changes nothing and costs one row.
    */
    groupPriceFor($tree['course']);

    return Cohort::factory()->create([
        'workspace_id' => $tree['workspace']->getKey(),
        'course_id' => $tree['course']->getKey(),
        'created_by' => $tree['owner']->getKey(),
        ...$attributes,
    ]);
}

/*
| ⛔ **انقلبَت.** كانَ عنوانُها «يُغلِقُ الشجرةَ كلَّها ما دامَت هناكَ مجموعةٌ
| يستطيعُ الانضمامَ إليها»، وهي الحالةُ التي قاسَت الشرطَ المُلغى نصّاً. وهي
| الآنَ برهانُ FR-015: **مجموعةٌ مفتوحةٌ موجودة، والطالبُ ليسَ فيها، والمنهجُ
| مفتوح.**
|
| ⛔ **وهذه هي الحالةُ التي كانت تقعُ على الإنتاج:** «Laravel Mastery» أُنشِئَت
| لها مجموعةٌ في ٢٠٢٦-٠٩-١٠ فانغلقَ المنهجُ على أربعةِ تسجيلاتٍ من أربعةٍ سجّلَت
| في ٢٠٢٦-٠٨-٢٨ — أحدُها عندَ ١٠٠٪ وكلُّ دروسِه مكتملة.
*/
it('opens the tree even while a joinable group exists, because membership is no longer a condition', function (): void {
    $tree = $this->curriculumTree();
    addCohort($tree, ['name' => 'السبت ٤م']);

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    $payload = $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertOk()->json();

    // ⚠️ الحمولةُ **لم تتغيّر**: الثلاثةُ تُنتِجُ الجملةَ وتُميِّزُ حالتَيها،
    // ولا يُشتَقُّ منها قفلٌ في أيِّ موضع.
    expect($payload['cohort_gate'])->toMatchArray([
        'required' => true,
        'satisfied' => false,
        'joinable_exists' => true,
    ]);

    /*
    | ⛔ **فاعلانِ، والتوكيدُ عليهما معاً — قرارُ المالكِ ٢٠٢٦-٠٩-١٤.** كانَت
    | هذه الحالةُ تقيسُ أنّ الجملةَ تُسمّي «الإدارة» وحدَها، وهي جملةٌ **صحيحةٌ
    | ونصفُها ناقص**: للطالبِ أن ينضمَّ بنفسِه إلى مجموعةٍ مفتوحة (بشرطِ ألّا
    | يكونَ في مجموعةٍ أخرى من الكورس)، فجملةٌ تُسمّي الإدارةَ وحدَها تتركُه
    | ينتظرُ أمامَ زرٍّ يعمل. والإسنادُ الإداريُّ بابٌ **إضافيٌّ** لا بديل، فلا
    | يُحذَفُ ذكرُه.
    |
    | ⚠️ وبلا «ال» التعريف: الجملتانِ تختلفانِ في صيغتِها («إدارةُ المنصّة» ·
    | «تُسنِدك الإدارة»)، والتوكيدُ على **مَن سُمِّيَ فاعلاً** لا على هجاءِ
    | الجملة.
    */
    expect($payload['cohort_gate']['message'])
        ->toContain('إدارة')
        ->toContain('انضمّ');

    expect(lockCodes($payload))->not->toContain('no_cohort');

    // ⚠️ الضابط: بوّاباتُ الكورسِ نفسِها لم تُفتَح. بدونَه يمرُّ بناءٌ سلَّمَ
    // المنهجَ كلَّه لمن لم يُتِمَّ درساً واحداً.
    expect(lockCodes($payload))->toContain(LessonAccess::SEQUENCE);
});

/*
| ⛔ **انقلبَت جزئيّاً.** الفتحُ كانَ يقعُ بالصمّامِ وصارَ يقعُ دائماً، فما بقيَ
| لها أن تقيسَه هو **الجملةُ الثانية**: «لا مجموعةَ أصلاً» غيرُ «مجموعاتٌ متاحةٌ
| ولستَ فيها». وكلمةُ «مدرّسك» انتقلَت إلى «الإدارة» لأنّ الفاعلَ تغيّر.
*/
it('says the other sentence when there is no group to be put into', function (): void {
    $tree = $this->curriculumTree();

    // كلُّ طرقِ «لا شيءَ يُنضَمُّ إليه»، واحدةٌ لكلِّ طريق.
    addCohort($tree, ['name' => 'ممتلئة', 'capacity' => 1, 'members_count' => 1]);
    addCohort($tree, ['name' => 'مغلقة', 'status' => Cohort::CLOSED]);
    addCohort($tree, ['name' => 'مؤرشفة', 'status' => Cohort::ARCHIVED, 'archived_at' => now()]);

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    $payload = $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertOk()->json();

    expect($payload['cohort_gate'])->toMatchArray([
        // ⚠️ ما زالَت `required`. الكورسُ **يعملُ** بالمجموعاتِ فعلاً، وإخفاءُ
        // ذلكَ يتركُ الطالبَ لا يفهمُ سجلَّ مجموعةٍ يراه زملاؤُه.
        'required' => true,
        'satisfied' => false,
        'joinable_exists' => false,
    ]);

    expect($payload['cohort_gate']['message'])
        ->toContain('إدارة')
        // ⚠️ «مدرّسك» كانت هنا، وهي اليومَ تسميةٌ للفاعلِ الخطأ.
        ->not->toContain('مدرّسك');

    expect(lockCodes($payload))->not->toContain('no_cohort')
        ->and(lockCodes($payload))->toContain(LessonAccess::SEQUENCE);
});

/*
| ⚠️ **باقيةٌ كما هي، وهي الضابط.** كورسٌ بلا مجموعاتٍ إطلاقاً: لا جملة، ولا
| `required`. بدونَها يمرُّ بناءٌ فتحَ الكورساتِ كلَّها بآليّةٍ لا علاقةَ لها
| بالمجموعات، وتوكيداتُ الفتحِ أعلاه تصيرُ صحيحةً لسببٍ آخرَ تماماً.
*/
it('leaves a course with no groups at all exactly as it was (FR-036)', function (): void {
    $tree = $this->curriculumTree();

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    $payload = $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertOk()->json();

    expect($payload['cohort_gate'])->toMatchArray([
        'required' => false,
        'satisfied' => true,
        'joinable_exists' => false,
        'message' => null,
    ]);

    expect(lockCodes($payload))->not->toContain('no_cohort');
});

/*
| ⛔ **حُذِفَت: «ترفعُ البوّابةَ عن الطالبِ لحظةَ انضمامِه».**
|
| نتيجتُها لم تعُدْ قابلةً للإنتاجِ بأيِّ طريق: لا بوّابةَ تُرفَع. وتوكيدُها —
| «لا `no_cohort` بعدَ الانضمام» — صارَ صحيحاً **قبلَ** الانضمامِ وبعدَه وبلا
| مجموعاتٍ أصلاً، أي جملةٌ تمرُّ على كلِّ بناءٍ ممكنٍ فلا تحرسُ شيئاً. وبقاؤُها
| مع `JoinCohort` — الذي يُغلَقُ بابُه في T030 — كانَ سيجعلُها تُقاسُ على مسارٍ
| سيُحذَف. وما تبقّى من معناها (أنّ العضويّةَ تُغيّرُ `satisfied`) يقعُ في
| `AdminAssignsCohortTest` مع البابِ الذي صارَ يكتبُها.
*/

/*
| ⛔ **انقلبَت — والبابانِ يتّفقانِ كما كانا.** `forTree()` يبني الحمولةَ أعلاه
| و`for()` يحرسُ `/learn/lessons/{uuid}`، وهو **السطحُ الوحيدُ في المنتَجِ الذي
| يُشغِّلُ درساً**. فحذفُ فرعٍ من أحدِهما وتركُه في الآخرِ هو بعينِه عيبُ
| «بابانِ يختلفان» الذي جعلَ تسجيلاً مدفوعاً غيرَ قابلٍ للفتحِ في ٠١٨.
*/
it('serves the lesson itself, with the body, to a student in no group', function (): void {
    $tree = $this->curriculumTree();
    addCohort($tree, ['name' => 'السبت ٤م']);

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    // ⚠️ التوكيدُ على **المحتوى** لا على `can_access` وحدَه: هذا الباب شكلُه
    // ٢٠٠ يحملُ رفضاً، فقراءةُ الحالةِ وحدَها تمرُّ فوقَ درسٍ مُفرَغٍ من نصِّه.
    $this->getJson('/api/v1/learn/lessons/'.$tree['lessons']['open']->uuid)
        ->assertOk()
        ->assertJsonPath('can_access', true)
        ->assertJsonPath('blocked_reason', null)
        ->assertJsonPath('lesson.content', $tree['lessons']['open']->content);
});
