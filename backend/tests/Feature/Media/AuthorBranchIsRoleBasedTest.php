<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ⛔ **«المؤلّف» دورٌ في المحور، لا مجرّدُ عضويّةٍ في مساحةِ العمل.**
|
| ثلاثةُ أبوابٍ كانت تسألُ «أهوَ عضوٌ في مساحةِ عملِ الدرس؟» وتُجيبُ بنعم على
| كلِّ ما فيها **بما فيه المسوّدات**، قبلَ أيِّ فحصٍ للحالةِ تحتَها:
| `IssuePlaybackGrant::mayWatch()` و`mayWatchMany()` و`/learn/lessons/{uuid}`،
| وهو البابُ الوحيدُ في المنتَجِ الذي يفتحُ صفحةَ درس.
|
| ⚠️ **وحجّتُها المكتوبةُ كانت «الطالبُ ليسَ عضواً في مساحةِ عمل»، وهي صحيحةٌ
| عن الطالبِ الذي سجّلَ نفسَه وكاذبةٌ في العموم.** قِيسَ على قاعدةٍ حقيقيّةٍ في
| ٢٠٢٦-٠٩-٠٩: `workspace_members` تحملُ **ستّةَ صفوفٍ بدورِ `student`** —
| يكتبُها `addWorkspaceMember` و`AcceptInvitation` والبذور. فأولئكَ الستّةُ
| كانوا يرَونَ مسوّداتِ مدرّسِهم: درسٌ لم يُنشَرْ بعدُ وفيديو رُفِعَ للتجربة.
|
| والسؤالُ بالنفيِ (`role != student`) كما في `User::teachesOnPlatform()`،
| فدورٌ مخصَّصٌ مجهولٌ يسقطُ نحوَ المنعِ لا نحوَ الفتح.
|
| **كيفَ يمسك**: أعِدْ أيَّ بابٍ منها إلى `workspaces()->where(...)->exists()`
| وحدَه ⇒ تسقطُ حالتُه وحدَها.
*/
beforeEach(function (): void {
    $this->tree = $this->scopedTree();

    // عضوٌ بدورِ «طالب» — الشكلُ الذي يُنتِجُه مدرّسٌ يضيفُ طالبَه بنفسِه.
    $this->member = $this->addWorkspaceMember($this->tree['workspace'], 'student');

    $built = app(WorkspaceContext::class)->forWorkspace($this->tree['workspace'], function (): array {
        $draft = Lesson::create([
            'workspace_id' => $this->tree['workspace']->getKey(),
            'course_id' => $this->tree['course']->getKey(),
            'section_id' => $this->tree['chapter']->section_id,
            'chapter_id' => $this->tree['chapter']->getKey(),
            'uuid' => Str::uuid(),
            'title' => 'درسٌ لم يُنشَرْ بعد',
            'type' => 'video',
            'status' => ContentStatus::Draft,
            'order' => 9,
        ]);

        // ⚠️ **ومسجَّلٌ نشطٌ حتماً.** آخرُ فرعٍ في `mayWatch()` هو التسجيل،
        // فعضوٌ غيرُ مسجَّلٍ يُرفَضُ لسببٍ آخرَ تماماً والحالةُ خضراءُ كاذبة.
        Enrollment::create([
            'workspace_id' => $this->tree['workspace']->getKey(),
            'course_id' => $this->tree['course']->getKey(),
            'student_user_id' => $this->member->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);

        return ['draft' => $draft];
    });

    $this->draft = $built['draft'];
    $this->grants = app(IssuePlaybackGrant::class);
});

it('refuses the video of a draft item to a member whose pivot role is student', function (): void {
    expect($this->grants->mayWatch($this->draft->fresh(), $this->member))->toBeFalse();
});

it('refuses it in the bulk form too, and still opens the published item beside it', function (): void {
    $verdict = $this->grants->mayWatchMany(
        [$this->draft->fresh(), $this->tree['lessons']['shared_first']],
        $this->member,
    );

    expect($verdict[$this->draft->getKey()])->toBeFalse()
        // الضابط: الرفضُ عن المسوّدةِ لا عن الشخص. بدونَه تمرُّ الحالةُ على
        // بناءٍ يرفضُ كلَّ شيءٍ لأيِّ سبب.
        ->and($verdict[$this->tree['lessons']['shared_first']->getKey()])->toBeTrue();
});

/*
| ⛔ **والبابُ الثالثُ هو الصفحةُ نفسُها، وهو أسوأُ الثلاثة.**
|
| `showLessonForViewer()` يسألُ عن التسجيلِ أوّلاً، فإن لم يجدْ سألَ عن
| العضويّة — فعضوٌ بدورِ «طالب» **غيرُ مسجَّلٍ في الكورس** كانَ يُجابُ
| `LessonAccess::allow()`: كلُّ درسٍ في مساحةِ العملِ مفتوحاً له، المنشورُ
| والمسوّدةُ معاً، بلا تسجيلٍ وبلا ثمن. وهو شكلٌ عاديٌّ تماماً — مدرّسٌ يضيفُ
| طالبَه إلى مساحتِه ثمّ يسجّلُه في كورسٍ واحدٍ من ثلاثة.
|
| ⚠️ **والتجهيزةُ بلا تسجيلٍ عن قصد**: العضوُ المسجَّلُ يمرُّ من فرعِ التسجيلِ
| أعلاه ولا يبلغُ هذا السطرَ أبداً، فحالةٌ مبنيّةٌ عليه خضراءُ على بناءٍ فيه
| العطلُ كاملاً.
|
| **كيفَ يمسك**: أعِدِ الشرطَ إلى `workspaces()->where(...)->exists()` ⇒ تسقطُ
| هذه وحدَها بـ٢٠٠ بدلَ ٤٠٤.
*/
it('refuses the lesson page to a member with that role and no enrolment', function (): void {
    $outsider = $this->addWorkspaceMember($this->tree['workspace'], 'student');

    Sanctum::actingAs($outsider);
    app()->forgetInstance(WorkspaceContext::class);

    // المنشورُ أوّلاً: هذا محتوًى يُشترى، والعضويّةُ ليست شراءً.
    $this->getJson('/api/v1/learn/lessons/'.$this->tree['lessons']['shared_first']->uuid)
        ->assertNotFound();

    $this->getJson('/api/v1/learn/lessons/'.$this->draft->uuid)->assertNotFound();
});

it('still opens both doors for a member who really does teach there', function (): void {
    $teacher = $this->addWorkspaceMember($this->tree['workspace'], 'teacher');

    expect($this->grants->mayWatch($this->draft->fresh(), $teacher))->toBeTrue();

    Sanctum::actingAs($teacher);
    app()->forgetInstance(WorkspaceContext::class);

    $this->getJson('/api/v1/learn/lessons/'.$this->draft->uuid)->assertOk();
});
