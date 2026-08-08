<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\DTOs\LessonData;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\CourseDuration;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\TreeDeletionGuard;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Create, edit and remove a content item.
 *
 * The type rules live here rather than in the FormRequest because the Action is
 * the entry point seeders, Filament and the API all share — a rule enforced only
 * in validation is a rule the panel walks past.
 */
class ManageLessons extends Action
{
    public function __construct(
        // Injected rather than called statically: it is the only path that takes
        // the bytes down at the provider as well as the row, and a second way to
        // remove an asset is a second way to leave a file behind.
        private readonly DeleteMediaAsset $deleteAsset,
    ) {}

    public function create(Course $course, Chapter $chapter, LessonData $data): Lesson
    {
        $this->assertImplemented($data->type);

        return DB::transaction(function () use ($course, $chapter, $data): Lesson {
            $lesson = new Lesson([
                'workspace_id' => $course->workspace_id,
                'course_id' => $course->getKey(),
                // Both derived from the one chapter the caller named. Asking for
                // a section as well is what let a lesson hold a section from one
                // branch and a chapter from another.
                'section_id' => $chapter->section_id,
                'chapter_id' => $chapter->getKey(),
                'title' => $data->title,
                'type' => $data->type->value,
                // Stated rather than left to the column default — see
                // ManageSections::create().
                'status' => ContentStatus::Draft,
                'content' => $data->content,
                'external_url' => $data->externalUrl,
                'reference_id' => $this->resolveReference($course, $data, $data->type),
                // Stated, not defaulted in the column: a model built with `new`
                // carries no column default, which is how `status` and `kind`
                // were silently null earlier in this spec. `Attempt` is the
                // weaker gate — see ExamGate.
                'exam_gate' => $data->type === LessonType::Exam
                    ? ($data->examGate ?? ExamGate::Attempt)
                    : null,
                'duration_seconds' => $data->durationSeconds ?? 0,
                'is_preview' => $data->isPreview,
                'is_free' => $data->isFree,
            ]);
            $lesson->save();

            $course->increment('structure_version');
            CourseDuration::recompute($course);

            return $lesson;
        });
    }

    public function update(Lesson $lesson, LessonData $data, ?Chapter $chapter = null): Lesson
    {
        $attributes = [
            'title' => $data->title,
            'content' => $data->content,
            'external_url' => $data->externalUrl,
            'is_preview' => $data->isPreview,
            'is_free' => $data->isFree,
        ];

        if ($chapter !== null) {
            $attributes['section_id'] = $chapter->section_id;
            $attributes['chapter_id'] = $chapter->getKey();
        }

        $type = $this->typeOf($lesson);

        if ($data->referenceUuid !== null && $lesson->course !== null) {
            $attributes['reference_id'] = $this->resolveReference($lesson->course, $data, $type);
        }

        // Only on the type that has one. A gate written onto an article is a
        // column nobody reads until someone changes the type and inherits a
        // condition they never set.
        if ($type === LessonType::Exam && $data->examGate !== null) {
            $attributes['exam_gate'] = $data->examGate;
        }

        // Duration is read from the uploaded file for the kinds that have one
        // (FR-015). Accepting it from the teacher for those would let the number
        // under the play button disagree with the file above it.
        if ($data->durationSeconds !== null && LessonTypeRegistry::assetKind($this->typeOf($lesson))?->hasDuration() !== true) {
            $attributes['duration_seconds'] = $data->durationSeconds;
        }

        $lesson->update($attributes);

        if ($lesson->course !== null) {
            CourseDuration::recompute($lesson->course);
        }

        return $lesson->refresh();
    }

    public function delete(Lesson $lesson): void
    {
        TreeDeletionGuard::assertLessonDeletable($lesson);

        // Attachments follow the item — but through DeleteMediaAsset, not a bulk
        // `->delete()` on the relation.
        //
        // The bulk form removed the rows and left the BYTES on disk: a file
        // nothing can reach, list, or clean up, and nothing to say it was ever
        // there. It also skipped revoking live grants, so a viewer mid-stream
        // kept reading a file with no owner. The guard above has already refused
        // this delete if a PRIMARY asset exists, so what is swept here is
        // attachments only — the primary still leaves through its own
        // two-factor route (FR-038أ).
        foreach ($lesson->attachments as $attachment) {
            $this->deleteAsset->handle($attachment);
        }

        DB::transaction(function () use ($lesson): void {
            $course = $lesson->course;

            $course?->increment('structure_version');
            $lesson->delete();

            if ($course !== null) {
                CourseDuration::recompute($course);
            }
        });
    }

    private function typeOf(Lesson $lesson): LessonType
    {
        return LessonType::from($lesson->type);
    }

    /**
     * The exam or session this item places, resolved from its uuid.
     *
     * Resolved through the workspace-scoped model rather than trusted from the
     * payload: a uuid is guessable in principle and the scope is the only thing
     * that says the exam belongs to the teacher asking.
     */
    private function resolveReference(Course $course, LessonData $data, LessonType $type): ?int
    {
        if ($data->referenceUuid === null) {
            return null;
        }

        // The type is taken from the ITEM, not from the DTO. On an update the
        // DTO's type is whatever the controller carried forward, and a
        // `live_session` item edited through it used to fall past the `Exam`
        // branch and resolve to null — the picker wrote nothing, silently.
        if ($type === LessonType::Exam) {
            $exam = Exam::query()
                ->where('uuid', $data->referenceUuid)
                ->where('course_id', $course->getKey())
                ->first();

            if ($exam === null) {
                throw new DomainException('الاختبار المحدَّد غير موجود في هذا الكورس.');
            }

            return (int) $exam->getKey();
        }

        if ($type === LessonType::LiveSession) {
            $session = ClassSession::query()
                ->where('uuid', $data->referenceUuid)
                ->where('course_id', $course->getKey())
                ->first();

            if ($session === null) {
                throw new DomainException('الحصة المحدَّدة غير موجودة في هذا الكورس.');
            }

            return (int) $session->getKey();
        }

        return null;
    }

    /**
     * Refuses a type that is declared but not built.
     *
     * Named rather than generic: "assignments arrive with the question bank" is
     * an answer; "invalid type" sends the teacher to look for their own mistake.
     */
    private function assertImplemented(LessonType $type): void
    {
        if (LessonTypeRegistry::isImplemented($type)) {
            return;
        }

        throw new DomainException(
            'الواجبات لم تُفعَّل بعد — تصل مع بنك الأسئلة. اختر نوعاً آخر لهذا العنصر.',
        );
    }
}
