<?php

declare(strict_types=1);

use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Cohort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ٠٢٦ · US1 — الكتابة: مَن يملكُ المفتاحَ وماذا يرفضُه.
*/
beforeEach(function (): void {
    $this->tree = $this->scopedTree();

    Sanctum::actingAs($this->tree['owner']);
    $this->setCurrentWorkspace($this->tree['workspace'], $this->tree['owner']);
});

function saveAudience(Lesson $lesson, array $uuids)
{
    return test()->putJson(
        '/api/v1/courses/'.test()->tree['course']->uuid.'/lessons/'.$lesson->uuid,
        ['cohort_uuids' => $uuids],
    );
}

function scopeCount(Lesson $lesson): int
{
    return (int) DB::table('lesson_cohort_scopes')->where('lesson_id', $lesson->getKey())->count();
}

it('narrows an item to a group of its own course', function (): void {
    $lesson = $this->tree['lessons']['shared_first'];

    saveAudience($lesson, [(string) $this->tree['mine']->uuid])->assertOk();

    expect(scopeCount($lesson))->toBe(1);
});

/*
| ⛔ **والكورسُ جزءٌ من السؤال.** بدونَه يستطيعُ مدرّسٌ أن يقصرَ عنصرَه على
| مجموعةٍ عندَ مدرّسٍ آخر، فلا يراه أحدٌ من طلابِه أبداً — وهو إخفاءٌ دائمٌ
| بضغطةٍ تبدو صحيحة.
*/
it('refuses a group that belongs to another course', function (): void {
    $otherCourse = Course::factory()->published()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'created_by' => $this->tree['owner']->getKey(),
    ]);

    $stranger = Cohort::factory()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'course_id' => $otherCourse->getKey(),
        'created_by' => $this->tree['owner']->getKey(),
    ]);

    $lesson = $this->tree['lessons']['shared_first'];

    saveAudience($lesson, [(string) $stranger->uuid])->assertStatus(422);

    expect(scopeCount($lesson))->toBe(0);
});

/*
| ⛔ **ومصفوفةٌ فارغةٌ تعليمةٌ لا صمت.** غيابُ الصفِّ هو «للجميع»، فالفارغُ
| يُعيدُ العنصرَ إلى ما وُلِدَ عليه — ولو قُرِئَ صمتاً لصارَ إلغاءُ التضييقِ
| زرّاً بلا أثر.
*/
it('clears the narrowing when it is sent an empty list', function (): void {
    $lesson = $this->tree['lessons']['scoped_away'];

    expect(scopeCount($lesson))->toBe(1);

    saveAudience($lesson, [])->assertOk();

    expect(scopeCount($lesson))->toBe(0);
});

/*
| ⚠️ **وحفظٌ لا يذكرُ الحقلَ لا يمسُّ المحور.** شاشةُ التحريرِ تحفظُ العنوانَ
| والمحتوى في طلباتٍ لا تذكرُ المجموعات؛ فلو قُرِئَ الغيابُ «امحُ» لمحا كلُّ
| تغييرِ عنوانٍ كلَّ نطاقٍ على العنصر، بصمت.
*/
it('leaves the narrowing alone on a save that never mentions it', function (): void {
    $lesson = $this->tree['lessons']['scoped_away'];

    $this->putJson(
        '/api/v1/courses/'.$this->tree['course']->uuid.'/lessons/'.$lesson->uuid,
        ['title' => 'عنوانٌ جديد'],
    )->assertOk();

    expect(scopeCount($lesson))->toBe(1);
});

/*
| ⛔ **والحدثُ يقعُ عندَ التغيُّرِ وحدَه.** المستمعُ يُعيدُ مزامنةَ كلِّ تسجيلاتِ
| الكورس، فإطلاقُه على كلِّ حفظٍ يعني مزامنةً كاملةً كلّما أعادَ المدرّسُ تسميةَ
| درسٍ وحفظَ الشاشةَ بما فيها.
*/
it('announces the structure change once, and not at all when nothing moved', function (): void {
    Event::fake([CourseStructureChanged::class]);

    $lesson = $this->tree['lessons']['scoped_away'];
    $same = [(string) $this->tree['theirs']->uuid];

    saveAudience($lesson, $same)->assertOk();

    Event::assertNotDispatched(CourseStructureChanged::class);

    saveAudience($lesson, [(string) $this->tree['mine']->uuid])->assertOk();

    Event::assertDispatchedTimes(CourseStructureChanged::class, 1);
});
