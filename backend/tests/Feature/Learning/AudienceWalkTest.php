<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

uses(MediaFixtures::class);

/*
| ⛔ ٠٢٦ · T048 · SC-001 — **«ما يُعرَضُ» = «ما يُفتَح»، صفرُ فروق.**
|
| هذه هي المشيةُ التي طلبَتها المواصفةُ يدويّاً، مكتوبةً حتّى تُعادَ في كلِّ
| بناء: بحسابِ طالبٍ **من كلِّ مجموعة**، يُقرَأُ المنهجُ ثمّ يُطرَقُ **كلُّ
| عنصرٍ في الكورس** — لا المعروضُ وحدَه — على كلِّ بابٍ يملكُه ذلك العنصر.
|
| ⚠️ **والعينُ ترى المعروضَ ولا ترى المحجوب**، وهذا هو نصفُ العطلِ كلُّه: صفٌّ
| مُسقَطٌ من الشجرةِ وبابُه مفتوحٌ لمن يحملُ المعرّفَ هو «بابانِ يختلفان» —
| وبالضبط ما جعلَ تسجيلاً **مدفوعاً** غيرَ قابلٍ للفتحِ في ٠١٨ بالأدوارِ
| معكوسة. فالمشيةُ هنا تطرقُ المحجوبَ كما تطرقُ المعروض.
|
| ⚠️ **والكورسُ غيرُ متسلسلٍ عمداً.** في كورسٍ متسلسلٍ يكونُ كلُّ ما بعدَ الصفِّ
| الأوّلِ مقفولاً بالتسلسل، فتصيرُ المقارنةُ بينَ «معروضٍ» و«مفتوحٍ» محكومةً
| بسببٍ لا علاقةَ له بهذه المواصفة، وتبقى خضراءَ على بناءٍ لا يسألُ الحكمَ
| إطلاقاً.
|
| **كيفَ يمسك**: احذفْ سؤالَ `LessonAudience` من أيِّ بابٍ من الأربعةِ ⇒ يسقطُ
| بـ«عنصرٌ محجوبٌ من المنهجِ فتحَه البابُ الفلانيّ».
*/

/**
 * @return array{
 *     workspace: Workspace,
 *     course: Course,
 *     lessons: array<string, Lesson>,
 *     students: array<string, User>,
 * }
 */
function audienceWalkFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    Storage::fake('local');

    [$workspace, $owner] = $test->createWorkspaceWithOwner();

    // ⚠️ بلا `addWorkspaceMember`: الطالبُ الحقيقيُّ سياقُه فارغٌ، و`WorkspaceScope`
    // يصيرُ عديمَ الأثرِ عليه — فتجهيزةٌ بعضويّةٍ تقيسُ شخصاً آخر.
    $students = [
        'alpha' => User::factory()->create(),
        'beta' => User::factory()->create(),
        'loose' => User::factory()->create(),
    ];

    $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $students): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'is_sequential' => false,
            'cover_path' => 'courses/cover.jpg',
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
            'course_id' => $course->getKey(),
            'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $order = 0;
        $make = function (string $key, array $extra = []) use ($workspace, $course, $section, $chapter, &$order): Lesson {
            $order++;

            return Lesson::create([
                'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
                'uuid' => Str::uuid(), 'title' => $key, 'type' => 'article',
                'status' => ContentStatus::Published, 'order' => $order,
                'content' => 'نصّ', 'is_free' => false, 'is_preview' => false,
                ...$extra,
            ]);
        };

        $cohortNamed = fn (string $name): Cohort => Cohort::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $owner->getKey(),
            'name' => $name,
        ]);

        $alpha = $cohortNamed('مجموعة ألف');
        $beta = $cohortNamed('مجموعة باء');

        $examLesson = function (string $key) use ($workspace, $course, $make): Lesson {
            $exam = Exam::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'title' => $key,
            ]);

            $lesson = $make($key, ['type' => 'exam', 'content' => null, 'reference_id' => $exam->getKey()]);

            // ورقةٌ فيها سؤال، وإلّا فلا شيءَ يُجمَّدُ عندَ البدء.
            bankQuestion($workspace, $exam);

            return $lesson;
        };

        $videoLesson = function (string $key) use ($workspace, $make): Lesson {
            $lesson = $make($key, ['type' => 'video', 'content' => null]);

            $asset = MediaAsset::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'owner_type' => Lesson::class,
                'owner_id' => $lesson->getKey(),
                'status' => MediaAssetStatus::Ready,
            ]);

            Storage::disk('local')->put((string) $asset->provider_asset_id, str_repeat('v', 4096));

            return $lesson->refresh();
        };

        $session = function (bool $delivered) use ($workspace, $course): ClassSession {
            $row = ClassSession::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
            ]);

            if ($delivered) {
                $row->forceFill([
                    'status' => ClassSessionStatus::Completed,
                    'delivered_at' => now()->subDay(),
                ])->save();
            }

            return $row;
        };

        $lessons = [
            'shared' => $make('shared'),
            'alpha_only' => $make('alpha_only'),
            'beta_only' => $make('beta_only'),
            'waiting' => $make('waiting'),
            'released' => $make('released'),
            'exam_shared' => $examLesson('exam_shared'),
            'exam_beta' => $examLesson('exam_beta'),
            'video_beta' => $videoLesson('video_beta'),
        ];

        $narrow = function (Lesson $lesson, Cohort $cohort) use ($workspace): void {
            LessonCohortScope::query()->create([
                'workspace_id' => $workspace->getKey(),
                'lesson_id' => $lesson->getKey(),
                'cohort_id' => $cohort->getKey(),
            ]);
        };

        $narrow($lessons['alpha_only'], $alpha);
        $narrow($lessons['beta_only'], $beta);
        $narrow($lessons['exam_beta'], $beta);
        $narrow($lessons['video_beta'], $beta);

        $lessons['waiting']->forceFill(['release_session_id' => $session(false)->getKey()])->save();
        $lessons['released']->forceFill(['release_session_id' => $session(true)->getKey()])->save();

        foreach ($students as $key => $student) {
            Enrollment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);

            $cohort = match ($key) {
                'alpha' => $alpha,
                'beta' => $beta,
                default => null,
            };

            if ($cohort === null) {
                continue;
            }

            CohortMembership::query()->create([
                'workspace_id' => $workspace->getKey(),
                'cohort_id' => $cohort->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'joined_at' => now(),
            ]);
        }

        return ['course' => $course, 'lessons' => $lessons];
    });

    return ['workspace' => $workspace, 'students' => $students, ...$built];
}

beforeEach(function (): void {
    $this->fx = audienceWalkFixture();
});

/**
 * يُوقِعُ الطالبَ في سياقِه الحقيقيّ، ومعه جلسةٌ تربطُ إليها منحةُ التشغيل.
 */
function walkAs(User $student): void
{
    Sanctum::actingAs($student);
    test()->asGuest();

    $session = test()->sessionFor($student);
    $session->forceFill(['token_id' => $student->currentAccessToken()->getKey()])->save();
}

/**
 * ما يعرضُه المنهجُ لهذا الطالب: **معرّفُ الدرسِ ⇒ حالتُه**.
 *
 * @return array<string, string>
 */
function shownToWalker(string $courseUuid): array
{
    $payload = test()->getJson('/api/v1/courses/'.$courseUuid.'/curriculum')->assertOk()->json();

    $shown = [];

    foreach ($payload['sections'] as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                $shown[(string) $lesson['uuid']] = (string) $lesson['state'];
            }
        }
    }

    return $shown;
}

/**
 * كلُّ بابٍ يملكُه هذا العنصرُ وهل فُتِحَ فعلاً: **اسمُ الباب ⇒ فُتِح؟**
 *
 * @return array<string, bool>
 */
function audienceDoorsOf(Lesson $lesson): array
{
    /** @var TestCase $test */
    $test = test();

    /*
    | ⚠️ **«فُتِح» = دونَ ٣٠٠، لا رقمٌ بعينِه لكلِّ باب.** الثلاثةُ تردُّ أرقاماً
    | مختلفةً عندَ النجاح (صفحةُ الدرسِ ٢٠٠، بدءُ المحاولةِ ٢٠١، منحةُ التشغيلِ
    | ٢٠٠)، ومقارنةُ رقمٍ خاطئٍ تقرأُ فتحاً سليماً «رفضاً» — وهو فارقٌ كاذبٌ
    | يبحثُ القارئُ عن عطلٍ لا وجودَ له.
    */
    $opened = static fn (TestResponse $response): bool => $response->status() < 300;

    $doors = [
        // البابُ الوحيدُ في المنتَجِ الذي يفتحُ صفحةَ درس.
        'lesson_page' => $opened($test->getJson('/api/v1/learn/lessons/'.$lesson->uuid)),
    ];

    if ($lesson->type === 'exam') {
        $exam = Exam::query()->withoutWorkspaceScope()->findOrFail($lesson->reference_id);

        $doors['exam_start'] = $opened($test->postJson('/api/v1/exams/'.$exam->uuid.'/attempts'));

        // الفهرسُ بابٌ ثانٍ للورقةِ نفسِها: يُعرَضُ أو لا يُعرَض.
        $index = $test->getJson('/api/v1/exams')->assertOk()->json();
        $rows = $index['data'] ?? $index;

        $doors['exam_index'] = in_array($exam->uuid, array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $rows,
        ), true);
    }

    if ($lesson->type === 'video') {
        $doors['playback'] = $opened($test->postJson('/api/v1/lessons/'.$lesson->uuid.'/playback'));
    }

    return $doors;
}

it('opens exactly what it shows, for a student in every group and for one in none', function (): void {
    $differences = [];
    $openedSomething = false;

    foreach ($this->fx['students'] as $who => $student) {
        walkAs($student);

        $shown = shownToWalker($this->fx['course']->uuid);

        foreach ($this->fx['lessons'] as $key => $lesson) {
            // «معروضٌ ومفتوح» هو التوقُّع؛ وكلُّ ما عداه (مُسقَطٌ من الشجرة،
            // أو معروضٌ بقفل) يجبُ أن يُرفَضَ عندَ كلِّ بابٍ يملكُه العنصر.
            $expected = ($shown[$lesson->uuid] ?? null) === 'open';

            foreach (audienceDoorsOf($lesson) as $door => $opened) {
                if ($opened !== $expected) {
                    $differences[] = sprintf(
                        '%s: «%s» %s in the curriculum but %s at %s',
                        $who,
                        $key,
                        $expected ? 'open' : ($shown[$lesson->uuid] ?? 'absent'),
                        $opened ? 'opened' : 'refused',
                        $door,
                    );
                }

                $openedSomething = $openedSomething || $opened;
            }
        }
    }

    /*
    | ⚠️ **والضابطُ الموجَبُ قبلَ التوكيدِ الصفريّ.** بناءٌ يرفضُ كلَّ بابٍ لكلِّ
    | طالبٍ يُرضي «صفرَ فروق» إرضاءً تامّاً، وشجرةٌ فارغةٌ كذلك — وكلاهما نقيضُ
    | ما تقولُه SC-001.
    */
    expect($openedSomething)->toBeTrue('every door refused every student — the walk measured nothing');

    expect($differences)->toBe([], "«ما يُعرَض» فارقَ «ما يُفتَح»:\n".implode("\n", $differences));
});

/*
| ⚠️ **والمشيةُ لا تُثبِتُ أنّ الإخفاءَ وقعَ أصلاً**: منهجٌ يعرضُ كلَّ شيءٍ لكلِّ
| أحدٍ وأبوابٌ تفتحُ كلَّ شيءٍ لكلِّ أحدٍ متّفقانِ اتّفاقاً تامّاً. فهذا الشقُّ
| يقيسُ الفرقَ بينَ الحسابات.
*/
it('shows each group a different tree, and the loose student only the shared items', function (): void {
    $seen = [];

    foreach ($this->fx['students'] as $who => $student) {
        walkAs($student);
        $seen[$who] = array_keys(shownToWalker($this->fx['course']->uuid));
    }

    $uuid = fn (string $key): string => (string) $this->fx['lessons'][$key]->uuid;

    expect($seen['alpha'])->toContain($uuid('alpha_only'))
        ->and($seen['alpha'])->not->toContain($uuid('beta_only'))
        ->and($seen['beta'])->toContain($uuid('beta_only'))
        ->and($seen['beta'])->not->toContain($uuid('alpha_only'))
        // بلا مجموعةٍ ⇒ المشتَركُ وحدَه؛ ولا يُقفَلُ عليه المنهجُ (٠٣٤ · FR-015).
        ->and($seen['loose'])->toContain($uuid('shared'))
        ->and($seen['loose'])->not->toContain($uuid('alpha_only'))
        ->and($seen['loose'])->not->toContain($uuid('beta_only'));

    // والمحورُ الثاني يُخفي ويُظهِرُ للجميعِ سواءً — فهو خاصّيّةُ العنصرِ لا القارئ.
    foreach ($seen as $who => $uuids) {
        // ⚠️ `toContain` مُتغيّرُ المعاملات: نصٌّ ثانٍ هنا إبرةٌ أخرى لا رسالة.
        expect($uuids)->not->toContain($uuid('waiting'))
            ->and($uuids)->toContain($uuid('released'));
    }
});
