<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| SC-004. The course page is the one screen in this phase whose row count has no
| ceiling — a real course is two hundred items, and the obvious implementation
| asks the gate once per row.
|
| ⚠️ TWO SIZES, NEVER ONE. A cap of 20 on a ten-row fixture passes at 20 for ten
| rows and again at 20 for two hundred: it says the number is small, never that
| it is CONSTANT. And equality at one size says nothing either — a per-row
| implementation measured twice at the same size agrees with itself perfectly.
|
| ⚠️ AND EVERY MEASUREMENT IS WARMED FIRST. spatie's permission cache is filled by
| the first authenticated request in the process, so an unwarmed measurement
| carries the cache fill and a warmed one does not — the bigger page then looks
| CHEAPER by a fixed handful of queries, and an N+1 five rows deep hides inside
| the difference.
|
| ⚠️ AND THE COUNT IS NOT THE WHOLE TEST. Dropping an eager load beside a
| `whenLoaded` produces NO N+1 at all: the key simply goes missing, the page gets
| one query cheaper, and a budget measuring cost alone reports the regression as
| an improvement — then the tree renders with no state on any row. So the fields
| are asserted present in the same breath. That is the pair of opposite mistakes,
| and one assertion cannot guard both.
*/

/** @return array{0: int, 1: array<string, mixed>} */
function measureCurriculum(object $test, array $fixture): array
{
    Sanctum::actingAs($fixture['student']);
    $test->forgetWorkspace();

    $url = '/api/v1/courses/'.$fixture['course']->uuid.'/curriculum';

    /*
     | TWO warm-ups, not one. Measured on the heartbeat budget: the first request
     | after a cold start does not touch every `platform_settings` key the path
     | reads — creating a row and updating one are different branches reading
     | different settings — so request two still carries a cached-forever read or
     | two that request three does not. One warm-up leaves the measurement
     | drifting by one or two queries between identical runs, which is a budget
     | that flakes rather than a budget that bites.
     */
    $test->getJson($url)->assertOk();
    $test->getJson($url)->assertOk();

    [$count, $response] = countingQueries(fn () => $test->getJson($url)->assertOk());

    return [$count, $response->json()];
}

it('answers a ten-lesson course and a two-hundred-lesson course in the same number of queries', function (): void {
    [$small, $smallPayload] = measureCurriculum($this, $this->curriculumOfSize(10));
    [$large, $largePayload] = measureCurriculum($this, $this->curriculumOfSize(200));

    $rowsIn = function (array $payload): array {
        $rows = [];

        foreach ($payload['sections'] as $section) {
            foreach ($section['chapters'] as $chapter) {
                $rows = [...$rows, ...$chapter['lessons']];
            }
        }

        return $rows;
    };

    // The control: the big course really is twenty times the small one, so the
    // equality below is about the implementation and not about two empty trees.
    expect($rowsIn($smallPayload))->toHaveCount(10)
        ->and($rowsIn($largePayload))->toHaveCount(200);

    expect($large)->toBe(
        $small,
        "curriculum cost {$small} queries for 10 lessons and {$large} for 200 — the gate is being asked per row",
    );
});

it('carries the state, the lock and the cover — the fields a cheaper page would be missing', function (): void {
    [, $payload] = measureCurriculum($this, $this->curriculumOfSize(200));

    expect($payload['course'])->toHaveKeys([
        'cover_url', 'teacher_name', 'progress_pct', 'completed_count', 'countable_count', 'resume_lesson_uuid',
    ]);

    $states = [];

    foreach ($payload['sections'] as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                expect($lesson)->toHaveKeys(['uuid', 'title', 'type', 'family', 'state', 'lock', 'duration_seconds']);

                $states[$lesson['state']] = true;

                if ($lesson['state'] === 'locked') {
                    // A locked row with a null lock is the payload equivalent of
                    // «مقفول» with nothing after it.
                    expect($lesson['lock']['code'] ?? null)->not->toBeNull()
                        ->and($lesson['lock']['message'] ?? null)->not->toBeEmpty();
                }
            }
        }
    }

    // A sequential 200-lesson course the student has not started: one open item
    // and a long tail of locked ones. Both states present means the walk really
    // ran rather than defaulting every row to the same answer.
    expect($states)->toHaveKey('open')
        ->and($states)->toHaveKey('locked');
});

/*
| ⛔ ٠٢٦ · SC-004 — **وشجرةٌ فيها نطاقاتٌ ومواعيدُ إفراج.**
|
| التجهيزةُ فوقَ هذا السطرِ لا تحملُ صفَّ نطاقٍ واحداً ولا ربطَ حصّةٍ واحداً،
| و`LessonAudience` يخرجُ من محورِه الأوّلِ باستعلامٍ يرجعُ فارغاً ومن الثاني
| بلا استعلامٍ أصلاً — أي أنّ الحالتَينِ فوقَ هذا السطرِ تقيسانِ **الفرعَ الذي
| لا يُسأَلُ**، وتبقيانِ خضراوَينِ على بناءٍ يسألُ عن مجموعاتِ القارئِ مرّةً
| لكلِّ صفّ.
|
| ⚠️ **وحصّةٌ مستقلّةٌ لكلِّ ربطٍ عمداً** — للسببِ الذي تكتبُه التجهيزةُ نفسُها:
| معرّفٌ واحدٌ مشتركٌ يجعلُ القراءةَ لكلِّ صفٍّ والقراءةَ مرّةً واحدةً تتّفقانِ
| على الحجمَينِ معاً، فلا يقولُ التساوي شيئاً.
|
| **كيفَ يمسك**: انقلْ `openMembershipCohortIdsFor()` أو `releasedSessionIds()`
| إلى داخلِ حلقةِ الصفوف ⇒ يسقطُ بفارقٍ يُقاسُ بالمئات.
*/

/**
 * يُضيِّقُ ربعَ الشجرةِ ويربطُ سُدسَها بحصص — نصفُ كلٍّ مرئيٌّ ونصفُه لا.
 *
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function narrowAndDefer(array $fixture): array
{
    $workspace = $fixture['workspace'];
    $course = $fixture['course'];
    $student = $fixture['student'];

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $course, $student): void {
        $cohort = fn (string $name): Cohort => Cohort::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $course->created_by,
            'name' => $name,
        ]);

        $mine = $cohort('مجموعتي');
        $theirs = $cohort('مجموعةٌ أخرى');

        CohortMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'cohort_id' => $mine->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'joined_at' => now(),
        ]);

        $lessons = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $course->getKey())
            ->orderBy('order')
            ->get();

        foreach ($lessons as $index => $lesson) {
            if ($index % 4 === 0) {
                LessonCohortScope::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'lesson_id' => $lesson->getKey(),
                    'cohort_id' => $index % 8 === 0 ? $mine->getKey() : $theirs->getKey(),
                ]);
            }

            if ($index % 6 !== 1) {
                continue;
            }

            $session = ClassSession::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
            ]);

            // نصفُها مُسلَّمةٌ فيظهرُ عنصرُها، ونصفُها مجدولةٌ فيُسقَط — والحكمانِ
            // يمرّانِ من القراءةِ الجماعيّةِ نفسِها.
            if ($index % 12 === 1) {
                $session->forceFill([
                    'status' => ClassSessionStatus::Completed,
                    'delivered_at' => now()->subDay(),
                ])->save();
            }

            $lesson->forceFill(['release_session_id' => $session->getKey()])->save();
        }
    });

    return $fixture;
}

it('answers a narrowed, release-linked tree at the same cost whatever its size', function (): void {
    [$small, $smallPayload] = measureCurriculum($this, narrowAndDefer($this->curriculumOfSize(10)));
    [$large, $largePayload] = measureCurriculum($this, narrowAndDefer($this->curriculumOfSize(200)));

    $rowsIn = function (array $payload): int {
        $rows = 0;

        foreach ($payload['sections'] as $section) {
            foreach ($section['chapters'] as $chapter) {
                $rows += count($chapter['lessons']);
            }
        }

        return $rows;
    };

    $smallRows = $rowsIn($smallPayload);
    $largeRows = $rowsIn($largePayload);

    /*
    | ⚠️ **الضابطُ الموجَبُ في اتّجاهَين.** صفوفٌ أقلُّ من المزروعِ تعني أنّ
    | الإخفاءَ وقعَ فعلاً؛ وصفوفٌ أكثرُ من صفرٍ تعني أنّ الشجرةَ لم تُفرَغْ بالكامل
    | — وشجرةٌ فارغةٌ تُرضي تساويَ التكلفةِ إرضاءً تامّاً.
    */
    expect($largeRows)->toBeGreaterThan(0)
        ->and($largeRows)->toBeLessThan(200)
        ->and($smallRows)->toBeGreaterThan(0)
        ->and($smallRows)->toBeLessThan(10);

    expect($large)->toBe(
        $small,
        "curriculum cost {$small} queries for 10 lessons and {$large} for 200 — the audience verdict is being asked per row",
    );
});
