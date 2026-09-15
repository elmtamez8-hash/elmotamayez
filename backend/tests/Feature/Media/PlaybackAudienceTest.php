<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ⛔ ٠٢٦ · T026 — **البابُ الذي يخدُمُ الملفَّ فعلاً.**
|
| `LessonGate` يقرّرُ ما يُعرَضُ في المنهج؛ و`IssuePlaybackGrant` يقرّرُ من
| يُشاهِد. وبابانِ يختلفانِ هو العطلُ بعينِه الذي جعلَ تسجيلاً **مدفوعاً** غيرَ
| قابلٍ للفتحِ في ٠١٨ — قالَ `mayWatch()` نعم وقالَ التسلسلُ لا، والفيديو لا
| يُفتَحُ إلّا من البابِ المغلق. وهنا الأدوارُ معكوسة: الصفُّ مخفيٌّ من المنهجِ
| والملفُّ يُخدَمُ لمن يحملُ المعرّف.
|
| ⚠️ **والطالبُ مسجَّلٌ ونشطٌ حتماً**، لأنّ آخرَ فرعٍ في `mayWatch()` هو
| `hasActiveEnrollment` — فطالبٌ غيرُ مسجَّلٍ يُرفَضُ لسببٍ آخرَ تماماً
| والاختبارُ أخضرُ كاذب.
*/
beforeEach(function (): void {
    $this->tree = $this->scopedTree();
    $this->grants = app(IssuePlaybackGrant::class);
});

it('refuses a playing grant for an item narrowed away from this student', function (): void {
    /** @var Lesson $lesson */
    $lesson = $this->tree['lessons']['scoped_away'];

    expect($this->grants->mayWatch($lesson->fresh(), $this->tree['student']))->toBeFalse();
});

it('refuses it in the bulk form too, and allows the shared items in the same call', function (): void {
    $lessons = array_values($this->tree['lessons']);

    $verdict = $this->grants->mayWatchMany($lessons, $this->tree['student']);

    expect($verdict[$this->tree['lessons']['scoped_away']->getKey()])->toBeFalse()
        ->and($verdict[$this->tree['lessons']['shared_first']->getKey()])->toBeTrue()
        ->and($verdict[$this->tree['lessons']['shared_last']->getKey()])->toBeTrue();
});

/*
| ⚠️ **والتجهيزةُ تُثبِتُ أنّ الرفضَ من هذا المحورِ لا من التسجيل**: الطالبُ
| نفسُه يفتحُ العنصرَ المشترَكَ في السطرَينِ أعلاه، فالتسجيلُ نشطٌ قطعاً.
*/
it('opens it for a student who is in the group it was narrowed to', function (): void {
    $insider = $this->addWorkspaceMember($this->tree['workspace'], 'teacher');

    /** @var Lesson $lesson */
    $lesson = $this->tree['lessons']['scoped_away'];

    // المؤلّفُ (عضوٌ بدورٍ غيرِ الطالب) يرى ما قَصَرَه — وهو استثناءُ FR-011،
    // مكتوبٌ مرّةً واحدةً في `LessonAudience` ومقروءٌ من الأبوابِ الأربعة.
    expect($this->grants->mayWatch($lesson->fresh(), $insider))->toBeTrue();
});
