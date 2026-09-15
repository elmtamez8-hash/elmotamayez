<?php

declare(strict_types=1);

use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
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

/*
| ٠٢٦ · US2 — المحورُ الثاني: «متى يظهر»، ويمرُّ من البابِ نفسِه.
*/

function saveRelease(Lesson $lesson, ?string $uuid)
{
    return test()->putJson(
        '/api/v1/courses/'.test()->tree['course']->uuid.'/lessons/'.$lesson->uuid,
        ['release_session_uuid' => $uuid],
    );
}

function courseSession(array $extra = []): ClassSession
{
    return ClassSession::factory()->create([
        'workspace_id' => test()->tree['workspace']->getKey(),
        'course_id' => test()->tree['course']->getKey(),
        ...$extra,
    ]);
}

it('links an item to a session of its own course, and announces it once', function (): void {
    Event::fake([CourseStructureChanged::class]);

    $lesson = $this->tree['lessons']['shared_first'];
    $session = courseSession();

    saveRelease($lesson, (string) $session->uuid)->assertOk();

    expect((int) $lesson->fresh()->release_session_id)->toBe((int) $session->getKey());

    /*
    | ⛔ **والحدثُ على هذا المحورِ وحدَه، لا على المجموعاتِ فقط.** الربطُ
    | يُخرِجُ العنصرَ من مقامِ التقدُّمِ (T031)، و`progress_pct` لا يُكتَبُ إلّا
    | عندَ إتمامِ درس — فبلا هذا الحدثِ يبقى رقمُ كلِّ مسجَّلٍ محسوباً على مقامٍ
    | تغيّرَ للتوّ، على الحفظِ الذي غيّرَه بنفسِه. وحالةُ «المحورانِ معاً» أدناه
    | خضراءُ بلا هذا السطر، لأنّ فرعَ المجموعاتِ يُطلِقُه على أيِّ حال.
    */
    Event::assertDispatchedTimes(CourseStructureChanged::class, 1);

    // وإعادةُ الربطِ بالحصّةِ نفسِها ليست تغييراً، فلا مزامنةَ كاملةً لها.
    saveRelease($lesson, (string) $session->uuid)->assertOk();

    Event::assertDispatchedTimes(CourseStructureChanged::class, 1);
});

/*
| ⛔ **والكورسُ جزءٌ من السؤالِ هنا أيضاً.** حصّةُ مدرّسٍ آخرَ قد لا تُسلَّمُ
| أبداً، فالعنصرُ المربوطُ بها يختفي عن كلِّ طلابِ صاحبِه إلى الأبدِ بلا سببٍ
| يظهرُ على أيِّ شاشة.
*/
it('refuses a session that belongs to another course', function (): void {
    $otherCourse = Course::factory()->published()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'created_by' => $this->tree['owner']->getKey(),
    ]);

    $stranger = ClassSession::factory()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'course_id' => $otherCourse->getKey(),
    ]);

    $lesson = $this->tree['lessons']['shared_first'];

    saveRelease($lesson, (string) $stranger->uuid)->assertStatus(422);

    expect($lesson->fresh()->release_session_id)->toBeNull();
});

/*
| ⛔ **و`null` صريحةٌ تفكُّ الربطَ — وهي مخرجُ FR-008.** حصّةٌ تأجّلت ولم تُلغَ
| تبقى «لم تُسلَّمْ» إلى الأبد، فبلا هذا البابِ يبقى المحتوى مدفوناً بلا طريقةٍ
| لإخراجِه. و`??` بدلَ `array_key_exists` عندَ قراءةِ الحمولةِ تجعلُ هذا الزرَّ
| بلا أثرٍ إطلاقاً، بصمت.
*/
it('unlinks the item when it is sent an explicit null', function (): void {
    $lesson = $this->tree['lessons']['shared_first'];
    $session = courseSession();

    saveRelease($lesson, (string) $session->uuid)->assertOk();
    expect($lesson->fresh()->release_session_id)->not->toBeNull();

    saveRelease($lesson, null)->assertOk();

    expect($lesson->fresh()->release_session_id)->toBeNull();
});

/*
| ⚠️ **وحفظٌ لا يذكرُ الحقلَ لا يمسُّ المحور** — كما في محورِ المجموعات.
*/
it('leaves the release alone on a save that never mentions it', function (): void {
    $lesson = $this->tree['lessons']['shared_first'];
    $session = courseSession();

    saveRelease($lesson, (string) $session->uuid)->assertOk();

    $this->putJson(
        '/api/v1/courses/'.$this->tree['course']->uuid.'/lessons/'.$lesson->uuid,
        ['title' => 'عنوانٌ جديد'],
    )->assertOk();

    expect((int) $lesson->fresh()->release_session_id)->toBe((int) $session->getKey());
});

/*
| ⛔ **والمحورانِ في حفظٍ واحدٍ يُطلِقانِ حدثاً واحداً.** كلاهما يُحرّكُ مقامَ
| التقدُّمِ لكلِّ مسجَّل، وحدثانِ يعنيانِ مزامنةً كاملةً مرّتَينِ لشيءٍ واحد.
*/
it('announces one structure change for a save that moves both axes', function (): void {
    Event::fake([CourseStructureChanged::class]);

    $lesson = $this->tree['lessons']['shared_first'];
    $session = courseSession();

    $this->putJson(
        '/api/v1/courses/'.$this->tree['course']->uuid.'/lessons/'.$lesson->uuid,
        [
            'cohort_uuids' => [(string) $this->tree['mine']->uuid],
            'release_session_uuid' => (string) $session->uuid,
        ],
    )->assertOk();

    Event::assertDispatchedTimes(CourseStructureChanged::class, 1);

    expect(scopeCount($lesson))->toBe(1)
        ->and((int) $lesson->fresh()->release_session_id)->toBe((int) $session->getKey());
});

/*
| ⚠️ **والمحرّرُ يقرأُ الربطَ من الحمولة.** بلا `release_session_uuid` على
| المورِدِ يرسمُ الحقلُ «يظهر الآن» لعنصرٍ مقفولٍ فعلاً، فالأداةُ الوحيدةُ التي
| تُظهِرُ الحالَ تكذبُ فيه.
*/
it('sends the link back on the teacher payload', function (): void {
    $lesson = $this->tree['lessons']['shared_first'];
    $session = courseSession();

    saveRelease($lesson, (string) $session->uuid)
        ->assertOk()
        ->assertJsonPath('release_session_uuid', (string) $session->uuid);

    $this->getJson('/api/v1/courses/'.$this->tree['course']->uuid.'/tree')
        ->assertOk()
        ->assertJsonPath('sections.0.chapters.0.lessons.0.release_session_uuid', (string) $session->uuid);
});
