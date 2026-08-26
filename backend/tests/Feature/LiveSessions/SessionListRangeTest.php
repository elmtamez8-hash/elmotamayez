<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| «حصصي» — مدى، لا كلُّ ما مضى.
|
| القائمةُ كانت تُطلَبُ بلا وسيطٍ أصلاً وتُصفَّحُ بخمسين، مرتَّبةً تصاعديّاً — فمساحةٌ
| فيها سنةُ عملٍ تفتحُ على أوّلِ أسبوعٍ درّسه المدرّسُ في حياتِه، تحتَ عنوانٍ يقول
| «حصصي»، على الشاشةِ التي يفتحُها ليبدأَ حصّةً بعدَ دقيقة.
|
| ⚠️ وحدُّ `to` هو موضعُ العيبِ الحقيقيّ: `starts_at` طابعٌ زمنيٌّ و`to` تاريخٌ
| مجرَّد، فـ`<= '2026-08-26'` يربطُ منتصفَ الليلِ ويُسقِطُ اليومَ كلَّه — وهو اليومُ
| المطلوبُ نفسُه. نفسُ الحدِّ كلّف `FreezePeriod::covering()` وإغلاقَ فترةِ تسويةٍ
| إصلاحَيهما من قبل.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 09:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function sessionAt(string $when, string $title): ClassSession
{
    $test = test();
    $starts = CarbonImmutable::parse($when);

    return ClassSession::factory()->create([
        'teacher_profile_id' => $test->teacher->getKey(),
        'course_id' => $test->course->getKey(),
        'title' => $title,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'duration_minutes' => 60,
    ]);
}

/** @return list<string> */
function titlesFrom(string $query): array
{
    $rows = test()->getJson('/api/v1/class-sessions?'.$query)->assertOk()->json('data');

    return array_map(fn (array $row): string => (string) $row['title'], $rows);
}

it('keeps a session late on the last day of the range', function (): void {
    sessionAt('2026-08-26 20:30:00', 'حصة المساء');

    Sanctum::actingAs($this->owner);

    /*
     | ⚠️ THE WHOLE TEST IS THE 20:30. Under `<= '2026-08-26'` this row is gone —
     | the filter binds midnight, so «اليوم» answers with everything before dawn
     | and nothing after it. A fixture at 09:00 would pass against the bug.
     */
    expect(titlesFrom('from=2026-08-26&to=2026-08-26'))->toBe(['حصة المساء']);
});

it('shows today and leaves the neighbouring days out of it', function (): void {
    sessionAt('2026-08-25 18:00:00', 'أمس');
    sessionAt('2026-08-26 08:00:00', 'الصباح');
    sessionAt('2026-08-26 23:59:00', 'قبل منتصف الليل');
    sessionAt('2026-08-27 08:00:00', 'غداً');

    Sanctum::actingAs($this->owner);

    expect(titlesFrom('from=2026-08-26&to=2026-08-26'))
        ->toBe(['الصباح', 'قبل منتصف الليل']);
});

it('reads the past newest first, which is the other face of the same defect', function (): void {
    sessionAt('2026-01-05 10:00:00', 'أقدم');
    sessionAt('2026-06-05 10:00:00', 'أحدث');

    Sanctum::actingAs($this->owner);

    // Ascending here would put page one on the teacher's first ever week again —
    // exactly what the unfiltered default did.
    expect(titlesFrom('to=2026-08-26&order=desc'))->toBe(['أحدث', 'أقدم']);
});

it('still reads forwards by default, so a calendar reads like a calendar', function (): void {
    sessionAt('2026-09-05 10:00:00', 'بعيدة');
    sessionAt('2026-08-27 10:00:00', 'قريبة');

    Sanctum::actingAs($this->owner);

    // The positive control: a test of `desc` alone passes against a build that
    // sorts descending always.
    expect(titlesFrom('from=2026-08-26'))->toBe(['قريبة', 'بعيدة']);
});

/*
| الفلترةُ بالمدرّسِ وبالمجموعة.
|
| ⚠️ و«المجموعة» هي الكورس: لا كيانَ مجموعةٍ في هذا المنتج، وكلُّ حصّةٍ قابلةٍ
| للجدولةِ تحملُ كورساً منذ ٠٠٦ — فالمسجَّلون فيه هم المجموعةُ الدائمة.
*/
it('narrows to one teacher of a workspace that has several', function (): void {
    $other = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $second = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $other->getKey(),
    ]);

    sessionAt('2026-08-27 10:00:00', 'حصتي');
    ClassSession::factory()->create([
        'teacher_profile_id' => $second->getKey(),
        'course_id' => $this->course->getKey(),
        'title' => 'حصة زميلي',
        'starts_at' => CarbonImmutable::parse('2026-08-27 12:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-08-27 13:00:00'),
    ]);

    Sanctum::actingAs($this->owner);

    expect(titlesFrom('teacher='.$this->teacher->uuid))->toBe(['حصتي']);
});

it('narrows to one course, which is what a group means here', function (): void {
    $second = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    sessionAt('2026-08-27 10:00:00', 'رياضيات');
    ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $second->getKey(),
        'title' => 'فيزياء',
        'starts_at' => CarbonImmutable::parse('2026-08-27 12:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-08-27 13:00:00'),
    ]);

    Sanctum::actingAs($this->owner);

    expect(titlesFrom('course='.$second->uuid))->toBe(['فيزياء']);
});

it('answers an unresolvable filter with nothing, never with everything', function (): void {
    sessionAt('2026-08-27 10:00:00', 'حصة');

    Sanctum::actingAs($this->owner);

    /*
     | ⚠️ THE FAILURE DIRECTION THAT MATTERS. A filter whose value cannot be
     | resolved and is therefore SKIPPED returns the unfiltered calendar — so a
     | teacher asking for one course silently reads every course, and in a
     | multi-teacher workspace, somebody else's day under their own heading.
     |
     | It is also why there is no `exists:` rule on either uuid: that rule is a
     | raw query with no tenant condition, so it would answer «this uuid is real»
     | to anybody who guessed one, before a policy runs.
     */
    expect(titlesFrom('course=00000000-0000-0000-0000-000000000000'))->toBe([]);
    expect(titlesFrom('teacher=00000000-0000-0000-0000-000000000000'))->toBe([]);
});
