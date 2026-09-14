<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CourseWaitlistEntry;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| ٠٣٤ — بابُ الدَّورِ يُقاسُ **عبرَ المسار**، لا بنداءِ الفعل.
|
| ⛔ **ثماني حالاتٍ في `CourseWaitlistTest` تنادي `JoinWaitlist` مباشرةً، فلا
| واحدةَ منها تمرُّ على ربطِ المسار — وهناكَ كانَ العطب.** قِيسَ على الإنتاجِ
| ٢٠٢٦-٠٩-١٤ بمتصفّحٍ حقيقيّ: طالبٌ مسجَّلُ الدخولِ يضغطُ «سجّلني في الدَّور»
| على كورسٍ مكتمِلٍ أمامَه، فيُجابُ
| `POST /api/v1/courses/{uuid}/waitlist` ← **٤٠٤**، ورسالةُ «العنصر المطلوب غير
| موجود أو حُذف» عن كورسٍ يقرأُ اسمَه في السطرِ نفسِه.
|
| السببُ: `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id`، وهو
| **مختومٌ لكلِّ طالبٍ أُضيفَ يوماً إلى مساحةِ عمل** — فالنطاقُ يعضُّ على الربطِ
| الضمنيِّ ولا يُحَلُّ كورسُ مدرّسٍ آخر.
|
| ⚠️ **والتركيبةُ تُبنى خطأً عن عمد**: الطالبُ مختومٌ على مساحةٍ **غيرِ** مساحةِ
| الكورس. ختمُه على مساحةِ الكورسِ يجعلُ الحالةَ خضراءَ فوقَ البناءِ المكسور —
| وهو بالضبطِ ما يفعلُه `addWorkspaceMember` على مساحةِ الكورسِ نفسِها.
*/
beforeEach(function (): void {
    $this->home = marketplaceWorkspace('أكاديميّة الكورس');
    $this->teacher = marketplaceTeacher($this->home);
    $this->elsewhere = marketplaceWorkspace('أكاديميّةٌ أخرى');

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->home,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->home->getKey(),
            'created_by' => $this->teacher->user_id,
        ]),
    );

    // مكتمِل: مجموعةٌ واحدةٌ ولا مقعدَ فيها — وإلّا رفضَ الفعلُ بـ«فيه مكان».
    Cohort::factory()->full()->create([
        'workspace_id' => $this->home->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->user_id,
    ]);

    app(WorkspaceContext::class)->forget();
});

/**
 * يضغطُ الزرَّ كما تضغطُه الصفحة.
 *
 * ⛔ **`forgetInstance`، لا `forget()` — وهذا هو سببُ أنّ الحزمةَ كلَّها لم تكن
 * تستطيعُ رؤيةَ هذا العطبِ إطلاقاً.** `WorkspaceContext::forget()` يكتبُ
 * `resolved = true` مع `resolvedId = null`: أي أنّه **يُثبِّتُ السياقَ على
 * العدمِ**، لا يُعيدُه إلى «لم يُحَلَّ بعد». فكلُّ تركيبةٍ تُناديه تقيسُ إنساناً
 * سياقُه مصفَّرٌ بالقوّةِ مهما كانَ `users.last_workspace_id` عندَه — وهو إنسانٌ
 * لا يُنتِجُه الإنتاجُ أبداً، لأنّ الطلبَ الحقيقيَّ يبدأُ بحاويةٍ جديدةٍ فيَحُلُّ
 * العمودَ. إسقاطُ النسخةِ من الحاويةِ هو الشكلُ الوحيدُ الذي يُعيدُ حالةَ
 * «لم يُحَلَّ بعد».
 */
function pressWaitlist(User $student, string $courseUuid): TestResponse
{
    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    return test()->postJson('/api/v1/courses/'.$courseUuid.'/waitlist');
}

it('registers a student whose last workspace is somebody else\'s', function (): void {
    /*
    | ⚠️ **`forceFill`، لأنّ `last_workspace_id` في `$guarded`.** كُتِبَت هذه
    | الحالةُ أوّلَ مرّةٍ بـ`factory()->create(['last_workspace_id' => ...])`،
    | والإسنادُ الجَماعيُّ **يُسقِطُ المحروسَ بصمت** — فوُلِدَ الطالبُ بسياقٍ `null`
    | وصارَ نسخةً من الحالةِ التي تليه، وخضراءَ فوقَ البناءِ المكسور. كُشِفَ
    | بحذفِ الإصلاحِ وإعادةِ التشغيل، لا بقراءةِ التوكيد.
    */
    $student = User::factory()->create();
    $student->forceFill(['last_workspace_id' => $this->elsewhere->getKey()])->save();

    pressWaitlist($student, (string) $this->course->uuid)
        ->assertCreated()
        ->assertJsonStructure(['uuid', 'joined_at']);

    expect(CourseWaitlistEntry::query()->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())
        ->where('course_id', $this->course->getKey())
        ->count())->toBe(1);
});

it('still registers the self-registered student, who is a member of nowhere', function (): void {
    /*
    | ⚠️ **الحارسُ في الاتّجاهِ الآخر.** هذا هو الطالبُ الشائعُ (سياقُه `null`
    | والنطاقُ خاملٌ عليه)، وكانَ يمرُّ قبلَ الإصلاحِ وبعدَه — فحالةٌ به وحدَه
    | خضراءُ فوقَ البناءِ المكسور، وهي الحالةُ الوحيدةُ التي كانت مكتوبة.
    */
    $student = User::factory()->create(['last_workspace_id' => null]);

    pressWaitlist($student, (string) $this->course->uuid)->assertCreated();

    expect(CourseWaitlistEntry::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('refuses a course that is not publicly listed, and writes nothing', function (): void {
    /*
    | ⚠️ **وهذا ما يجعلُ الحارسَ غيرَ أجوف.** إسقاطُ النطاقِ بلا `publiclyListed()`
    | يفتحُ كلَّ مسوّدةٍ على المنصّةِ لأيِّ حسابٍ يعرفُ مُعرِّفَها.
    */
    $this->course->forceFill(['status' => 'draft'])->save();

    $student = User::factory()->create(['last_workspace_id' => null]);

    pressWaitlist($student, (string) $this->course->uuid)->assertNotFound();

    expect(CourseWaitlistEntry::query()->withoutWorkspaceScope()->count())->toBe(0);
});
