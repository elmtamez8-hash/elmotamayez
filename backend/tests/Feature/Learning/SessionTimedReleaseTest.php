<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\LessonGate;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ٠٢٦ · US2 — «لا شيءَ يظهرُ قبلَ حصّتِه»، ومتى يسقطُ القفلُ عن نفسِه.
|
| ⚠️ **الأحوالُ الأربعةُ في ملفٍّ واحدٍ عن قصد.** تجهيزةٌ تُسلِّمُ الحصّةَ
| دائماً لا ترى الإخفاءَ أصلاً، وأخرى لا تُسلِّمُها أبداً لا ترى الإفراج —
| والفرقُ بينَهما هو المواصفةُ كلُّها.
|
| ⚠️ **والتوكيدُ على الحمولةِ لا على الحكمِ وحدَه**: `LessonGate` يجيبُ برمز،
| و`CurriculumResource` هو الذي يُسقِطُ الصفّ.
*/
beforeEach(function (): void {
    $this->tree = $this->scopedTree();
});

/** يربطُ عنصراً مشتَركاً بحصّةٍ من هذا الكورسِ على الحالِ المطلوب. */
function releaseOn(Lesson $lesson, array $state = []): ClassSession
{
    // داخلَ سياقِ مساحةِ العمل: `ClassSessionFactory` يبني ملفَّ مدرّسٍ،
    // و`BelongsToWorkspace` يملأُ عمودَه من السياقِ لا من هذه المصفوفة.
    $session = app(WorkspaceContext::class)->forWorkspace(
        test()->tree['workspace'],
        fn (): ClassSession => ClassSession::factory()->create([
            'workspace_id' => test()->tree['workspace']->getKey(),
            'course_id' => test()->tree['course']->getKey(),
            ...$state,
        ]),
    );

    // ⚠️ `forceFill`: العمودُ ليسَ في `$fillable` عمداً، والإسنادُ الجماعيُّ
    // **يُسقِطُ المفتاحَ في صمت** — فتجهيزةٌ بـ`update()` تُنشئُ درساً غيرَ
    // مربوطٍ بشيءٍ وتؤكّدُ عليه بثقة.
    $lesson->forceFill(['release_session_id' => $session->getKey()])->save();

    return $session;
}

/** عناوينُ المنهجِ كما يقرؤُها الطالبُ فعلاً — بحسابِه، وبسياقٍ يُحَلُّ من جديد. */
function releaseTitles(User $student, Enrollment $enrollment): array
{
    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    $payload = test()->getJson('/api/v1/courses/'.$enrollment->course->uuid.'/curriculum')
        ->assertOk()
        ->json('sections');

    $titles = [];

    foreach ($payload as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                $titles[] = $lesson['title'];
            }
        }
    }

    return $titles;
}

it('shows an item that is linked to no session at all, at once', function (): void {
    $titles = releaseTitles($this->tree['student'], $this->tree['enrollment']);

    expect($titles)->toContain('المشتَركُ الأخير');
});

/*
| ⛔ **حصّةٌ مجدولةٌ ⇒ العنصرُ غيرُ موجود.** ليسَ مقفولاً بسطرٍ يقول «انتظرْ
| حتّى الأحد»: صفٌّ مقفولٌ يُعلِنُ عن وجودِ شيءٍ ما كانَ الطالبُ ليعرفَه، وهو
| ما تُقرَّرُ مواصفةُ ٠٢٦ إسقاطَه لا وصفَه.
*/
it('hides an item whose session has not been held yet', function (): void {
    releaseOn($this->tree['lessons']['shared_last']);

    $titles = releaseTitles($this->tree['student'], $this->tree['enrollment']);

    expect($titles)->toContain('المشتَركُ الأوّل')
        ->and($titles)->not->toContain('المشتَركُ الأخير');
});

it('shows it, and opens it, once the session has been delivered', function (): void {
    releaseOn($this->tree['lessons']['shared_last'], [
        'status' => ClassSessionStatus::Completed,
        'delivered_at' => now()->subHour(),
    ]);

    $titles = releaseTitles($this->tree['student'], $this->tree['enrollment']);

    expect($titles)->toContain('المشتَركُ الأخير');

    $access = LessonGate::for(
        $this->tree['enrollment']->fresh(),
        $this->tree['lessons']['shared_last']->fresh(),
    );

    expect($access->allowed)->toBeTrue(
        'المشتَركُ الأخير مقفولٌ بسبب: '.(string) $access->code.' — '.(string) $access->message,
    );
});

/*
| ⛔ **وحصّةٌ أُلغيَتْ مُفرَجٌ عنها** (FR-008). الإلغاءُ قرارُ المدرّس، وحجبُ
| ملفّاتِ حصّةٍ لن تُعقَدَ أبداً عقوبةٌ للطالبِ على ذلكَ القرار — ولا فعلَ له
| يفتحُها، فهو دفنٌ دائمٌ لا تأجيل.
|
| **كيفَ يمسك**: أزِلْ ذراعَ `status = cancelled` من `releasedSessionIds()` ⇒
| تسقطُ هذه الحالةُ وحدَها وتبقى الثلاثُ خضراء.
*/
it('shows it when the session was cancelled, because it will never be held', function (): void {
    releaseOn($this->tree['lessons']['shared_last'], [
        'status' => ClassSessionStatus::Cancelled,
        'cancelled_at' => now()->subDay(),
    ]);

    $titles = releaseTitles($this->tree['student'], $this->tree['enrollment']);

    expect($titles)->toContain('المشتَركُ الأخير');
});

/*
| ⛔ **وطالبٌ مختومٌ بمساحةِ عملٍ أخرى يرى ما يراه غيرُه.**
| `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id`، وهو مطبوعٌ على
| كلِّ طالبٍ أُضيفَ يوماً إلى مساحةِ عمل — ستّةُ صفوفٍ بدورِ `student` على قاعدةٍ
| حقيقيّة. فقراءةٌ مُنطَقةٌ لحالِ الحصّةِ تجيبُ «لم يُفرَجْ عنها» لأولئك، أي
| **إخفاءُ الصفِّ بصمت**، وحكمٌ يختلفُ باختلافِ القارئِ لا باختلافِ العنصر.
|
| **كيفَ يمسك**: احذفْ `withoutWorkspaceScope()` من `releasedSessionIds()` ⇒
| تسقطُ هذه الحالةُ وحدَها.
|
| ⚠️ **و`forceFill` لا `create([...])`**: العمودُ في `User::$guarded`، فالإسنادُ
| الجماعيُّ يُسقِطُه في صمتٍ ويبني الطالبَ عديمَ السياقِ من جديد — فتمرُّ
| الحالةُ على بناءٍ معطوبٍ وتُقرَأُ حارساً.
*/
it('shows it to a student stamped with somebody else’s workspace', function (): void {
    releaseOn($this->tree['lessons']['shared_last'], [
        'status' => ClassSessionStatus::Completed,
        'delivered_at' => now()->subHour(),
    ]);

    [$otherWorkspace] = $this->createWorkspaceWithOwner();

    $this->tree['student']->forceFill(['last_workspace_id' => $otherWorkspace->getKey()])->save();

    $titles = releaseTitles($this->tree['student'], $this->tree['enrollment']);

    expect($titles)->toContain('المشتَركُ الأخير');
});
