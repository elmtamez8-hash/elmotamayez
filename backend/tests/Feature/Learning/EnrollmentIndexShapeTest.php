<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| ٠٢٩ · `FR-012` — فهرسُ التسجيلاتِ يحملُ غلافَه.
|
| ⚠️ هذا ليس تحسينَ عقدٍ من أجلِ بطاقةِ عدّ. `response()->json(Resource::collection($paginator))`
| **لا تُنادي `toResponse()` أبداً**، فالردُّ مصفوفةٌ عاريةٌ لا `{data, links, meta}` —
| والقرّاءُ **الأربعةُ** في الواجهةِ يقرؤونَ `res.data ?? []` ⇒ `undefined` ⇒ **صفرُ
| صفوفٍ لكلِّ طالبٍ على المنصّة**: شاشةُ «تعلّمي» خاليةٌ لمن اشترى ودفع، ومنتقي
| الكورساتِ في شراءِ الرصيدِ خالٍ، ومتجرُ النقاطِ لا يعرفُ مدرّسيه، وبطاقتا اللوحةِ
| صفران.
|
| ⚠️ وسببُ بقائِه: **لم يكن لهذا الفهرسِ اختبارٌ قطّ**. كلُّ اختباراتِ التسجيلاتِ
| تسألُ عن درسٍ أو عن إكمالِه؛ ولا واحدَ منها فتحَ القائمةَ نفسَها.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->teacher->getKey(),
        'title' => 'الفيزياء — الفصل الثالث',
    ]);

    /*
    | ⚠️ طالبٌ بـ`last_workspace_id` فارغ. لا شيءَ في مسارِ الطالبِ يكتبُ ذلك
    | العمود، فسياقُه `null` في الإنتاجِ دائماً — وتركيبةٌ تختمُه (بـ
    | `addWorkspaceMember` مثلاً) تقيسُ شخصاً لا وجودَ له.
    */
    $this->student = User::factory()->create(['last_workspace_id' => null]);
    $this->other = User::factory()->create(['last_workspace_id' => null]);
});

/**
 * تسجيلاتٌ بحالةٍ بعينِها لطالبٍ بعينِه، مكتوبةٌ تحتَ مساحةِ المدرّس.
 *
 * ⚠️ كورسٌ **جديدٌ** لكلِّ صفٍّ: `unique(workspace_id, course_id, student_user_id)`
 * — تسجيلٌ واحدٌ لكلِّ كورسٍ لكلِّ طالب. وستَّ عشرةَ نسخةً من كورسٍ واحدٍ ليست
 * «طالباً عندَه أكثرُ من صفحة» بل خرقُ فهرس.
 */
function enrolMany(User $student, Course $course, int $count, string $status = 'active'): void
{
    for ($i = 0; $i < $count; $i++) {
        // العنوانُ نفسُه في كلِّ كورس: الترتيبُ داخلَ الصفحةِ بـ`enrolled_at`
        // وكلُّها في اللحظةِ نفسِها، فتوكيدٌ على `data.0` بعنوانٍ يخصُّ صفّاً
        // بعينِه يقيسُ ترتيباً لم يَعِدْ به أحد.
        $enrolledCourse = Course::factory()->published()->create([
            'workspace_id' => $course->workspace_id,
            'created_by' => $course->created_by,
            'title' => $course->title,
        ]);

        Enrollment::factory()->create([
            'workspace_id' => $course->workspace_id,
            'course_id' => $enrolledCourse->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => $status,
        ]);
    }
}

function readEnrollments(User $student, string $query = ''): TestResponse
{
    test()->asGuest();
    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($student);

    return test()->getJson('/api/v1/enrollments'.$query);
}

it('wraps the page in data and declares the true total beside it', function (): void {
    // ستَّ عشرةَ: أكثرُ من صفحةٍ واحدة، فالمجموعُ لا يمكنُ أن يأتيَ من طولِ المصفوفة.
    enrolMany($this->student, $this->course, 16);
    // وصفوفُ طالبٍ آخرَ لا تُحتسَبُ في مجموعِ هذا الطالب.
    enrolMany($this->other, $this->course, 4);

    $response = readEnrollments($this->student)->assertOk();

    $response
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 16)
        // ⚠️ المسارُ `data.0.…` لا `0.…`: الغلافُ هو الفرقُ كلُّه، والصيغةُ
        // القديمةُ تُسقِطُه صامتةً — لا خطأَ ولا تحذيرَ ولا فرقَ في الحالة.
        ->assertJsonPath('data.0.course_title', 'الفيزياء — الفصل الثالث');

    expect($response->json('links'))->not->toBeNull();
});

it('counts each status on its own, since three numbers cannot come from one total', function (): void {
    enrolMany($this->student, $this->course, 3);
    enrolMany($this->student, $this->course, 2, 'completed');

    // بطاقةُ العدِّ تسألُ عن «جارية» و«مكتملة» منفصلتَين. وبلا هذا المُرشِّحِ
    // يبقى الجوابُ الوحيدُ المتاحُ هو عدُّ صفوفِ صفحةٍ من خمسةَ عشرَ — وهو رقمٌ
    // يتوقّفُ عند ١٥ مهما بلغَ عددُ الكورسات.
    readEnrollments($this->student, '?status=active')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);

    readEnrollments($this->student, '?status=completed')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('refuses an unknown status rather than dropping the filter', function (): void {
    enrolMany($this->student, $this->course, 2);

    /*
    | ⚠️ ٤٢٢ لا تجاهُلٌ صامت. مُرشِّحٌ يسقطُ بصمتٍ يُسلِّمُ القارئَ **كلَّ** تسجيلاتِه
    | تحتَ عنوانِ «كورساتٌ جارية» — وهو العطلُ نفسُه الذي كتبَه فهرسُ الشهاداتِ
    | بجوارِ مُرشِّحِه: رقمٌ خاطئٌ يبدو صحيحاً أسوأُ من خطأٍ ظاهر.
    */
    readEnrollments($this->student, '?status=graduated')->assertStatus(422);
});

it('answers an empty page with a zero total, not with an error', function (): void {
    readEnrollments($this->student)
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});
