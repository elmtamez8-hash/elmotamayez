<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\LessonAudience;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٢٦ · T011 — القارئُ الواحدُ الذي تسألُه الأبوابُ الأربعة.
|
| ⚠️ **هذا الملفُّ يقيسُ الحكمَ وحدَه، قبلَ أن يُوصَلَ ببابٍ واحد.** الرمزانِ
| اللذانِ يُصدِرُهما هذا الصنفُ لهما كاتبٌ هنا وقارئٌ هنا؛ وهُما يبلغانِ
| `LessonGate` في الشحنةِ التي تلي مباشرةً — ورمزٌ يبقى بقارئٍ بلا كاتبٍ هو
| الشكلُ الذي عاشَ به `ClassSessionStatus::Interrupted` طوراً كاملاً وكلُّ
| قارئٍ يظنُّه منفَّذاً.
*/
beforeEach(function (): void {
    [$this->workspace, $this->author] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->author);

    $this->teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->author->getKey(),
    ]);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->author->getKey(),
    ]);

    $section = Section::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    $this->chapter = Chapter::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $section->getKey(),
    ]);

    $this->mine = audienceCohort($this->course, 'مجموعتي');
    $this->theirs = audienceCohort($this->course, 'مجموعةٌ أخرى');

    /*
    | ⚠️ **الطالبُ عضوُ لا مساحة، وهذا هو الطالبُ الحقيقيّ.** مَن سجّلَ نفسَه
    | واشترى كورساً لا يُكتَبُ له صفٌّ في `workspace_members` ولا
    | `last_workspace_id` — وحالتانِ أدناه تكسرانِ هذه القاعدةَ عمداً، لأنّ
    | ستّةَ صفوفٍ بدورِ `student` مقيسةٌ على قاعدةٍ حقيقيّة.
    */
    $this->student = User::factory()->create();

    CohortMembership::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->mine->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'joined_at' => now(),
    ]);
});

/*
| ⚠️ **`audienceCohort` لا `makeCohort`.** مساعِدُ Pest دالّةٌ **عامّة**، وملفّانِ
| يُسمّيانِ واحداً باسمَينِ مختلفَينِ يقعانِ في `Cannot redeclare` أوّلَ ما
| يُحمَّلانِ في العاملِ نفسِه — وهو كلُّ تشغيلٍ بـ`--parallel`.
*/
function audienceCohort(Course $course, string $name): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'created_by' => $course->created_by,
        'name' => $name,
    ]);
}

/*
| ⚠️ **`release_session_id` يُكتَبُ بـ`forceFill` لا في مصفوفةِ الإنشاء.** العمودُ
| ليسَ في `$fillable` عن قصد — يكتبُه Action واحدٌ يتحقّقُ أنّ الحصّةَ من كورسِ
| الدرس — والإسنادُ الجمليُّ **يُسقِطُ المفتاحَ بصمت**. فتجهيزةٌ تمرّرُه هكذا
| تبني درساً بلا موعدٍ إطلاقاً، وكلُّ توكيدِ «مخفيٌّ حتّى تُعقَدَ الحصّة» يمرُّ
| على درسٍ لا ينتظرُ شيئاً — أخضرُ كاذبٌ يقيسُ عكسَ ما يدّعيه.
*/
function audienceLesson(array $attributes = []): Lesson
{
    $release = $attributes['release_session_id'] ?? null;
    unset($attributes['release_session_id']);

    $lesson = Lesson::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'chapter_id' => test()->chapter->getKey(),
        ...$attributes,
    ]);

    if ($release !== null) {
        $lesson->forceFill(['release_session_id' => $release])->save();
    }

    return $lesson;
}

function scopeTo(Lesson $lesson, Cohort $cohort): void
{
    LessonCohortScope::query()->create([
        'workspace_id' => $lesson->workspace_id,
        'lesson_id' => $lesson->getKey(),
        'cohort_id' => $cohort->getKey(),
    ]);
}

/** الحكمُ كما يقرؤُه بابٌ حقيقيّ: بحسابِ القارئِ، بسياقٍ يُحَلُّ من جديد. */
function hiddenAs(User $viewer, Lesson $lesson): ?string
{
    test()->actingAs($viewer);
    app()->forgetInstance(WorkspaceContext::class);

    return LessonAudience::hiddenFor($viewer, $lesson->fresh());
}

it('says nothing about an item nobody narrowed', function (): void {
    expect(hiddenAs($this->student, audienceLesson()))->toBeNull();
});

it('opens an item narrowed to a group the student is in', function (): void {
    $lesson = audienceLesson();
    scopeTo($lesson, $this->mine);

    expect(hiddenAs($this->student, $lesson))->toBeNull();
});

it('hides an item narrowed to a group the student is not in', function (): void {
    $lesson = audienceLesson();
    scopeTo($lesson, $this->theirs);

    expect(hiddenAs($this->student, $lesson))->toBe(LessonAudience::OUT_OF_SCOPE);
});

it('opens an item narrowed to several groups when the student is in one of them', function (): void {
    $lesson = audienceLesson();
    scopeTo($lesson, $this->theirs);
    scopeTo($lesson, $this->mine);

    expect(hiddenAs($this->student, $lesson))->toBeNull();
});

/*
| ⛔ **الطالبُ المختومُ بمساحةِ عملٍ أخرى — وهي الحالةُ التي تفصلُ القراءةَ
| المُنطَقةَ عن غيرِها.**
|
| `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id`، ويكتبُه
| `addWorkspaceMember` و`AcceptInvitation` والبذور. فلو قُرِئَت صفوفُ النطاقِ من
| النموذجِ (`LessonCohortScope::query()`) لعادَت **صفراً** لهذا القارئ، فيُقرَأُ
| العنصرُ «بلا نطاق» ويُفتَحُ له — بينما يُخفى عن زميلِه غيرِ المختوم. حكمٌ
| يختلفُ باختلافِ القارئِ، وهو نقضُ FR-013أ.
|
| **كيفَ يمسك**: بدِّلْ `DB::table('lesson_cohort_scopes')` بـ
| `LessonCohortScope::query()` ⇒ تسقطُ هذه الحالةُ وحدَها.
*/
it('hides it from a student stamped with a different workspace, exactly as from any other', function (): void {
    [$elsewhere] = $this->createWorkspaceWithOwner();

    $stranger = User::factory()->create();
    $stranger->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();

    $lesson = audienceLesson();
    scopeTo($lesson, $this->theirs);

    expect(hiddenAs($stranger, $lesson))->toBe(LessonAudience::OUT_OF_SCOPE);
});

/*
| ⛔ **وعضوُ مساحةِ العملِ بدورِ «طالب» طالبٌ، لا مؤلّف.**
|
| قِيسَ على قاعدةٍ حقيقيّةٍ في ٢٠٢٦-٠٩-٠٩: `workspace_members` تحملُ ستّةَ صفوفٍ
| بدورِ `student`. فاستثناءٌ مكتوبٌ «عضوٌ ⇒ يرى كلَّ شيء» يفتحُ لأولئكَ ما
| قُصِرَ على غيرِهم.
|
| **كيفَ يمسك**: احذفْ `where('role','!=',Roles::STUDENT)` ⇒ تسقطُ هذه الحالةُ
| وحدَها — وحالةُ المؤلّفِ تبقى خضراء، فهي لا تفرّقُ بينَ الشرطَين.
*/
it('hides it from a workspace member whose role is student', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $lesson = audienceLesson();
    scopeTo($lesson, $this->theirs);

    expect(hiddenAs($member, $lesson))->toBe(LessonAudience::OUT_OF_SCOPE);
});

/*
| **كيفَ يمسك**: احذفْ `exemptAuthor()` ⇒ تسقطُ هذه الحالةُ وحدَها. ومدرّسٌ لا
| يرى ما قَصَرَه بنفسِه لا يستطيعُ تصحيحَه (FR-011).
*/
it('shows the author everything, narrowed or waiting', function (): void {
    $narrowed = audienceLesson();
    scopeTo($narrowed, $this->theirs);

    $waiting = audienceLesson(['release_session_id' => audienceSession()->getKey()]);

    expect(hiddenAs($this->author, $narrowed))->toBeNull()
        ->and(hiddenAs($this->author, $waiting))->toBeNull();
});

function audienceSession(array $attributes = []): ClassSession
{
    return ClassSession::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'teacher_profile_id' => test()->teacher->getKey(),
        'course_id' => test()->course->getKey(),
        'cohort_id' => test()->mine->getKey(),
        ...$attributes,
    ]);
}

/** مقعدُ الطالبِ في الحصّة — المادّةُ تتبعُ المقعدَ لا تاريخَها (٠٣٦). */
function audienceSeat(ClassSession $session, User $student, BookingStatus $status = BookingStatus::Booked): void
{
    SessionBooking::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $session->workspace_id,
        'class_session_id' => $session->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => $status,
        'is_billable' => true,
        'booked_at' => now()->subDay(),
    ]);
}

it('hides an item waiting on a session that has not been held', function (): void {
    $lesson = audienceLesson(['release_session_id' => audienceSession()->getKey()]);

    expect(hiddenAs($this->student, $lesson))->toBe(LessonAudience::UNRELEASED);
});

it('shows it the moment that session is delivered, to whoever took that hour', function (): void {
    $session = audienceSession([
        'status' => ClassSessionStatus::Completed,
        'delivered_at' => now()->subHour(),
    ]);

    audienceSeat($session, $this->student);

    expect(hiddenAs($this->student, audienceLesson(['release_session_id' => $session->getKey()])))->toBeNull();
});

/*
| ⛔ **٠٣٦ — والتسليمُ وحدَه لا يُفرِج.** المادّةُ تتبعُ المقعدَ كما يتبعُه
| التسجيلُ منذُ ٠١٠ · FR-030، وإلّا تدفّقَت مادّةُ كلِّ حصّةٍ جايةٍ على كلِّ
| عضوٍ في المجموعةِ ولو لم يحجزْ منها ساعةً واحدة — وهو بالضبطِ حالُ مَن نفدَ
| رصيدُه.
|
| **كيفَ يمسك**: أعِدْ `applyRelease()` إلى «مُفرَجٌ عنها ⇒ ظاهرة».
*/
it('hides it from a member of the same group who never took that hour', function (): void {
    $session = audienceSession([
        'status' => ClassSessionStatus::Completed,
        'delivered_at' => now()->subHour(),
    ]);

    expect(hiddenAs($this->student, audienceLesson(['release_session_id' => $session->getKey()])))
        ->toBe(LessonAudience::NOT_MY_SESSION);
});

/*
| ⛔ **وحصّةٌ أُلغيَت مُفرَجٌ عنها** (FR-008). لن تأتيَ أبداً، فحجبُ ملفّاتِها
| إلى الأبدِ عقوبةٌ على قرارِ المدرّسِ تقعُ على الطالب — وهو القفلُ الذي لا فعلَ
| للطالبِ يفتحُه، أخطرُ شكلٍ يسجّلُه هذا المستودع.
|
| **كيفَ يمسك**: أزِلْ `status = cancelled` من `releasedSessionIds()` ⇒ تسقطُ
| هذه الحالةُ وحدَها.
*/
it('shows it when that session was cancelled, because it is never coming', function (): void {
    $session = audienceSession(['status' => ClassSessionStatus::Cancelled]);

    expect(hiddenAs($this->student, audienceLesson(['release_session_id' => $session->getKey()])))->toBeNull();
});

/*
| ⚠️ **والمحورانِ يلتقيانِ على عنصرٍ واحد، والنطاقُ يسبق**: «ليسَ لك» جوابٌ
| نهائيٌّ، و«لم يحنْ بعد» جوابٌ مؤقّت — فالإخبارُ بالثاني عمّا هو الأوّلُ وعدٌ
| بشيءٍ لن يأتيَ.
*/
it('answers out_of_scope first when an item is both narrowed away and waiting', function (): void {
    $lesson = audienceLesson(['release_session_id' => audienceSession()->getKey()]);
    scopeTo($lesson, $this->theirs);

    expect(hiddenAs($this->student, $lesson))->toBe(LessonAudience::OUT_OF_SCOPE);
});

/*
| ⚠️ **والجماعيُّ هو الأصلُ والمفردُ مشتقٌّ منه** — فالصيغةُ الجماعيّةُ تُقاسُ
| هي أيضاً، لا يُكتفى بالمفردةِ التي تمرُّ عليها كلُّ الحالاتِ أعلاه.
*/
it('answers for a whole tree in one pass, each item on its own facts', function (): void {
    $open = audienceLesson();
    $mine = audienceLesson();
    scopeTo($mine, $this->mine);
    $theirs = audienceLesson();
    scopeTo($theirs, $this->theirs);
    $waiting = audienceLesson(['release_session_id' => audienceSession()->getKey()]);

    $this->actingAs($this->student);
    app()->forgetInstance(WorkspaceContext::class);

    $verdict = LessonAudience::hiddenAmong(
        $this->student,
        Lesson::query()->withoutWorkspaceScope()->whereIn('id', [
            $open->getKey(), $mine->getKey(), $theirs->getKey(), $waiting->getKey(),
        ])->get(),
    );

    expect($verdict[$open->getKey()])->toBeNull()
        ->and($verdict[$mine->getKey()])->toBeNull()
        ->and($verdict[$theirs->getKey()])->toBe(LessonAudience::OUT_OF_SCOPE)
        ->and($verdict[$waiting->getKey()])->toBe(LessonAudience::UNRELEASED);
});
