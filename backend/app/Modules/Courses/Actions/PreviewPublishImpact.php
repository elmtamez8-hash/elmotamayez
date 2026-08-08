<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Shared\Actions\Action;
use App\Shared\Contracts\ProgressImpact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * What this publish will do to the students already enrolled (`FR-049`).
 *
 * A course with thirty people in it is not a document. Publishing a section adds
 * items to a denominator those thirty are measured against, changes which lesson
 * stands in front of which, and — in the other direction, through the same
 * endpoint — can archive the last thing a student had left. `FR-049` says the
 * teacher sees that before pressing, and `SC-018` says what they see is what
 * happens, to the percentage point.
 *
 * **Nothing here is a second implementation, and that is the whole design.**
 *
 * - The item list is resolved by `PublishTreeNodes::resolve()`, so a uuid from
 *   another course 404s here exactly as it would there.
 * - The batch is refused by `PublishTreeNodes::assertReady()`, so a preview
 *   cannot promise a publish that will 422 on an empty article.
 * - The denominator's non-status half is `Lesson::progressEligible()`, the same
 *   scope the real denominator uses.
 * - The percentage is `CourseProgress::percentage()`, the same formula
 *   `MarkLessonComplete` writes with.
 * - The students the exam backfill will credit are found through
 *   `ExamGateSatisfaction`, the same predicate that listener uses.
 *
 * Exactly one thing is simulated, because it must be: the three status
 * conditions, which is what the publish is about to change. Everything else is
 * borrowed. A preview that computed its own idea of "countable" would be a
 * second denominator, and the first day the two disagreed the teacher would be
 * shown a number no student ever had.
 */
class PreviewPublishImpact extends Action
{
    public function __construct(
        private readonly PublishTreeNodes $publish,
        // Through the interface, not Learning's models. The dependency between
        // these two modules runs one way — Learning reads the course tree, Courses
        // does not know enrolments exist — and an import here would be the first
        // edge pointing back (Constitution III).
        private readonly ProgressImpact $impact,
    ) {}

    /**
     * @param  list<array{uuid: string, status: string}>|null  $items  null means
     *                                                                 every draft node, which is what the "publish everything" button sends
     * @return array<string, mixed>
     */
    public function handle(Course $course, ?array $items = null): array
    {
        $sections = Section::query()->where('course_id', $course->getKey())
            ->orderBy('order')->get(['id', 'uuid', 'status', 'order']);

        $chapters = Chapter::query()->where('course_id', $course->getKey())
            ->orderBy('order')->get(['id', 'uuid', 'section_id', 'status', 'order']);

        $lessons = Lesson::query()->where('course_id', $course->getKey())
            ->orderBy('order')
            ->get(['id', 'uuid', 'section_id', 'chapter_id', 'title', 'type', 'status',
                'order', 'class_session_id', 'reference_id', 'exam_gate']);

        $items ??= $this->everyDraft($sections, $chapters, $lessons);

        $nodes = $this->publish->resolve($course, $items);
        $this->publish->assertReady($nodes);

        // The state each node would be left in. Keyed per table, because a
        // section and a lesson can hold the same serial id.
        $after = ['section' => [], 'chapter' => [], 'lesson' => []];

        foreach ($nodes as [$node, $status]) {
            $key = match (true) {
                $node instanceof Section => 'section',
                $node instanceof Chapter => 'chapter',
                default => 'lesson',
            };

            $after[$key][(int) $node->getKey()] = $status;
        }

        // The half of the denominator a publish cannot touch, from the scope that
        // owns it — including the deleted-exam exclusion, which lives inside it.
        /** @var list<int> $eligible */
        $eligible = Lesson::query()->where('course_id', $course->getKey())
            ->progressEligible()->pluck('lessons.id')->map(fn ($id): int => (int) $id)->all();

        $eligibleSet = array_flip($eligible);

        $visibleBefore = $this->visibleLessonIds($sections, $chapters, $lessons, []);
        $visibleAfter = $this->visibleLessonIds($sections, $chapters, $lessons, $after);

        $before = array_values(array_filter($visibleBefore, fn (int $id): bool => isset($eligibleSet[$id])));
        $countedAfter = array_values(array_filter($visibleAfter, fn (int $id): bool => isset($eligibleSet[$id])));

        $students = $this->impact->of(
            (int) $course->getKey(),
            $before,
            $countedAfter,
            $this->openingExamItems($nodes),
        );

        return [
            'structure_version' => (int) $course->structure_version,
            // Echoed back so the client publishes exactly what was costed. A
            // preview taken over one batch and a publish sent with another is two
            // questions with one answer shown.
            'items' => $items,
            'added_items' => count(array_diff($countedAfter, $before)),
            'removed_items' => count(array_diff($before, $countedAfter)),
            'students_affected' => $students['affected'],
            'largest_drop_pct' => $students['drop'],
            'largest_gain_pct' => $students['gain'],
            'resequenced' => $course->is_sequential
                ? $this->resequenced($sections, $chapters, $lessons, $visibleBefore, $visibleAfter, $eligibleSet)
                : [],
            'warnings' => $this->warnings($nodes, $visibleBefore, $visibleAfter),
        ];
    }

    /**
     * Every draft node, ancestors first.
     *
     * Derived here rather than sent by the client, so that the set the preview
     * costs and the set the publish receives cannot differ — the client publishes
     * the `items` this response carries.
     *
     * `=== draft`, never `!== published`. Archived means "I no longer teach
     * this"; sweeping it into a bulk publish would resurrect it into every
     * student's denominator from a button that said nothing about it.
     *
     * @param  Collection<int, Section>  $sections
     * @param  Collection<int, Chapter>  $chapters
     * @param  Collection<int, Lesson>  $lessons
     * @return list<array{uuid: string, status: string}>
     */
    private function everyDraft(Collection $sections, Collection $chapters, Collection $lessons): array
    {
        $items = [];

        foreach ($sections as $section) {
            if ($section->status === ContentStatus::Draft) {
                $items[] = ['uuid' => (string) $section->uuid, 'status' => ContentStatus::Published->value];
            }

            foreach ($chapters->where('section_id', $section->getKey()) as $chapter) {
                if ($chapter->status === ContentStatus::Draft) {
                    $items[] = ['uuid' => (string) $chapter->uuid, 'status' => ContentStatus::Published->value];
                }

                foreach ($lessons->where('chapter_id', $chapter->getKey()) as $lesson) {
                    if ($lesson->status === ContentStatus::Draft) {
                        $items[] = ['uuid' => (string) $lesson->uuid, 'status' => ContentStatus::Published->value];
                    }
                }
            }
        }

        return $items;
    }

    /**
     * The lessons a student would see, in tree order.
     *
     * The chain, exactly as `Lesson::visibleToStudents()` asks it — published
     * item inside published chapter inside published section — with the batch's
     * statuses laid over the stored ones. Publishing a CHAPTER alone is the case
     * that makes this necessary: no lesson is named in that batch, and every
     * already-published lesson inside it becomes visible.
     *
     * Order is preserved, because the sequential half reads it.
     *
     * @param  Collection<int, Section>  $sections
     * @param  Collection<int, Chapter>  $chapters
     * @param  Collection<int, Lesson>  $lessons
     * @param  array{section: array<int, ContentStatus>, chapter: array<int, ContentStatus>, lesson: array<int, ContentStatus>}|array{}  $after
     * @return list<int>
     */
    private function visibleLessonIds(Collection $sections, Collection $chapters, Collection $lessons, array $after): array
    {
        $visible = [];

        foreach ($sections as $section) {
            $sectionStatus = $after['section'][(int) $section->getKey()] ?? $section->status;

            if (! $sectionStatus->isVisibleToStudents()) {
                continue;
            }

            foreach ($chapters->where('section_id', $section->getKey()) as $chapter) {
                $chapterStatus = $after['chapter'][(int) $chapter->getKey()] ?? $chapter->status;

                if (! $chapterStatus->isVisibleToStudents()) {
                    continue;
                }

                foreach ($lessons->where('chapter_id', $chapter->getKey()) as $lesson) {
                    $status = $after['lesson'][(int) $lesson->getKey()] ?? $lesson->status;

                    if ($status->isVisibleToStudents()) {
                        $visible[] = (int) $lesson->getKey();
                    }
                }
            }
        }

        return $visible;
    }

    /**
     * The exam items this batch would open, as plain data.
     *
     * Handed across the module boundary rather than the models: Learning has to
     * know which exam and which gate in order to find the students it will credit
     * on publish, and nothing more. The condition matches
     * `CompleteExamLessonsAlreadyAnswered` exactly — a published exam item with a
     * reference — because whatever that listener credits is what the preview must
     * predict.
     *
     * @param  list<array{0: Model, 1: ContentStatus}>  $nodes
     * @return list<array{lesson_id: int, exam_id: int, gate: string}>
     */
    private function openingExamItems(array $nodes): array
    {
        $items = [];

        foreach ($nodes as [$node, $status]) {
            if ($node instanceof Lesson
                && $status === ContentStatus::Published
                && $node->type === LessonType::Exam->value
                && $node->reference_id !== null) {
                $items[] = [
                    'lesson_id' => (int) $node->getKey(),
                    'exam_id' => $node->reference_id,
                    'gate' => ($node->exam_gate ?? ExamGate::Attempt)->value,
                ];
            }
        }

        return $items;
    }

    /**
     * Items whose unlock prerequisite changes (`FR-049`).
     *
     * In a sequential course the gate is "finish the item immediately before
     * this one", and that item is whichever eligible, visible lesson precedes it
     * in tree order — so publishing something in the middle of a course silently
     * puts a new obstacle in front of everything after it. This is the list of
     * items where that happens.
     *
     * Only items visible BOTH before and after are listed: one that is being
     * published for the first time is already counted as an addition, and saying
     * its unlock order "changed" would be describing an order it never had.
     *
     * @param  Collection<int, Section>  $sections
     * @param  Collection<int, Chapter>  $chapters
     * @param  Collection<int, Lesson>  $lessons
     * @param  list<int>  $visibleBefore
     * @param  list<int>  $visibleAfter
     * @param  array<int, int>  $eligibleSet
     * @return list<array{uuid: string, title: string, unlocked_by: string|null}>
     */
    private function resequenced(Collection $sections, Collection $chapters, Collection $lessons, array $visibleBefore, array $visibleAfter, array $eligibleSet): array
    {
        $prerequisiteBefore = $this->prerequisites($visibleBefore, $eligibleSet);
        $prerequisiteAfter = $this->prerequisites($visibleAfter, $eligibleSet);

        $titles = $lessons->pluck('title', 'id');
        $uuids = $lessons->pluck('uuid', 'id');

        $bothVisible = array_intersect($visibleAfter, $visibleBefore);

        $changed = [];

        foreach ($bothVisible as $id) {
            $was = $prerequisiteBefore[$id] ?? null;
            $now = $prerequisiteAfter[$id] ?? null;

            if ($was === $now) {
                continue;
            }

            $changed[] = [
                'uuid' => (string) $uuids[$id],
                'title' => (string) $titles[$id],
                'unlocked_by' => $now === null ? null : (string) $titles[$now],
            ];
        }

        return $changed;
    }

    /**
     * For each visible lesson, the eligible one immediately before it.
     *
     * The same set `Enrollment::accessTo` walks back through — published chain,
     * completable type, not a recording, reference intact — which is why the
     * eligible set is passed in rather than recomputed: a prerequisite nobody can
     * complete is a permanent lock, and the two must agree about which items
     * those are.
     *
     * @param  list<int>  $visible
     * @param  array<int, int>  $eligibleSet
     * @return array<int, int|null>
     */
    private function prerequisites(array $visible, array $eligibleSet): array
    {
        $prerequisites = [];
        $previous = null;

        foreach ($visible as $id) {
            $prerequisites[$id] = $previous;

            if (isset($eligibleSet[$id])) {
                $previous = $id;
            }
        }

        return $prerequisites;
    }

    /**
     * The two batches that are worth stopping over (`FR-053` · `FR-055`).
     *
     * Neither is refused. A teacher is allowed to hide a recording and allowed to
     * pull a whole course back to draft; what they are not allowed to do is
     * discover afterwards what it meant.
     *
     * @param  list<array{0: Model, 1: ContentStatus}>  $nodes
     * @param  list<int>  $visibleBefore
     * @param  list<int>  $visibleAfter
     * @return list<array{code: string, message: string}>
     */
    private function warnings(array $nodes, array $visibleBefore, array $visibleAfter): array
    {
        $warnings = [];

        foreach ($nodes as [$node, $status]) {
            if ($node instanceof Lesson
                && $node->class_session_id !== null
                && ! $status->isVisibleToStudents()) {
                $warnings[] = [
                    'code' => 'recording_hidden',
                    'message' => "«{$node->title}» هو تسجيل حصة، وهذا هو الطريق الوحيد لمن حضرها إليه. إخفاؤه يقطعه عنهم.",
                ];
            }
        }

        if ($visibleAfter === [] && $visibleBefore !== []) {
            $warnings[] = [
                'code' => 'course_emptied',
                'message' => 'بعد هذا لن يبقى في الكورس عنصر واحد مرئي لطلابك — سيفتحونه فيجدونه فارغاً.',
            ];
        }

        return $warnings;
    }
}
