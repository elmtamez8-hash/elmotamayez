<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `GET /exams` يحملُ غلافَه — وبدونِه كانت **كلُّ قائمةِ اختباراتٍ في المنتَجِ
| فارغة**.
|
| `response()->json(Resource::collection($paginator))` **لا تُنادي `toResponse()`
| أبداً**، فيسقطُ `{data, links, meta}` في صمتٍ ويصيرُ الردُّ مصفوفةً عارية.
| والقرّاءُ الثلاثةُ في الواجهةِ يكتبونَ `res.data ?? []` — و`res.data` على
| مصفوفةٍ هو `undefined` — فكانت النتيجةُ صفرَ صفوفٍ في:
|
|  · `/exams` — شاشةُ الطالب، تقولُ «لا اختبارات متاحة الآن» لمن عنده أوراق.
|  · تبويبُ الاختباراتِ في صفحةِ الكورس.
|  · `/manage/exams` — تقولُ للمدرّسِ إنّه لم يكتبْ ورقةً قطّ.
|
| الخادمُ وحدَه كانَ المخطئَ والقرّاءُ الثلاثةُ مكتوبونَ صواباً، فالإصلاحُ سطرٌ
| ولم يتغيّرْ في الواجهةِ حرف. وهي عائلةُ عطلِ `/enrollments` نفسِها (٠٢٩).
|
| ⚠️ **وما أبقاه هو أنّ الفهرسَ لم يكنْ له اختبارُ شكلٍ قطّ.** كلُّ اختباراتِه
| تسألُ عن المحتوى — عنوانٌ حاضرٌ وآخرُ غائب — وذلكَ صحيحٌ على المصفوفةِ
| العاريةِ كما على المغلَّفة، والصيغتانِ تبعُدُ إحداهما عن الأخرى بحرفٍ على
| الشاشة.
|
| **كيفَ يمسك**: أعِدْ `response()->json(ExamResource::collection($exams))` ⇒
| يسقطُ الشقّانِ بـ«المفتاحُ `data` غائب».
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->teacher->getKey(),
    ]);

    foreach (range(1, 3) as $index) {
        Exam::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'title' => "PAPER-{$index}",
        ]);
    }

    // ⚠️ `last_workspace_id` فارغٌ: لا شيءَ في مسارِ الطالبِ يكتبُ ذلك العمود،
    // فسياقُه `null` في الإنتاجِ دائماً — وتركيبةٌ تختمُه تقيسُ شخصاً آخر.
    $this->student = User::factory()->create(['last_workspace_id' => null]);

    Enrollment::create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);
});

it('answers the student with an envelope the client can read', function (): void {
    Sanctum::actingAs($this->student);
    app()->forgetInstance(WorkspaceContext::class);

    $payload = $this->getJson('/api/v1/exams')->assertOk()->json();

    /*
    | ⚠️ **التوكيدُ على المفاتيحِ العليا، لا على `assertJsonStructure` وحدَها.**
    | المصفوفةُ العاريةُ مفاتيحُها `0` و`1` و`2` — فشرطٌ يقولُ «فيه صفوفٌ لها
    | عنوان» صحيحٌ على الشكلَينِ معاً، وهو بعينِه ما تركَ العطلَ يعيش.
    */
    expect(array_keys($payload))->toContain('data')
        ->and(array_keys($payload))->toContain('meta')
        ->and(array_keys($payload))->toContain('links');

    // والضابطُ الموجَب: غلافٌ حولَ لا شيءٍ غلافٌ كذلك.
    expect($payload['data'])->toHaveCount(3)
        ->and($payload['meta']['total'])->toBe(3);
});

/*
| ⚠️ **والمدرّسُ كذلك، وهو القارئُ الذي يظنُّ أحدٌ أنّه محميٌّ بسياقِه.** شاشةُ
| «إدارة الاختبارات» تقرأُ النقطةَ نفسَها بالسطرِ نفسِه، فكانت تقولُ للمدرّسِ
| إنّه لم يكتبْ ورقةً قطّ.
*/
it('answers the teacher with the same envelope', function (): void {
    Sanctum::actingAs($this->teacher);

    $payload = $this->getJson('/api/v1/exams')->assertOk()->json();

    expect(array_keys($payload))->toContain('data')
        ->and($payload['data'])->toHaveCount(3);
});
