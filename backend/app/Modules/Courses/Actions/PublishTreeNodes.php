<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Events\ExamItemOpened;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\CourseDuration;
use App\Modules\Courses\Support\PublishReadiness;
use App\Modules\Courses\Support\StructureVersion;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moves nodes between draft, published and archived — in one batch.
 *
 * A batch rather than one call per node, because publishing is rarely one node:
 * a section, its chapters and their items become visible together, and eleven
 * separate requests give the student eleven different half-built trees on the
 * way through.
 *
 * Required fields are enforced HERE and nowhere earlier (FR-022). Saving a draft
 * with an empty body has to work or a draft is not a draft; opening it to a
 * student with an empty body must not.
 */
class PublishTreeNodes extends Action
{
    use LogsActivity;

    /**
     * @param  list<array{uuid: string, status: string}>  $items
     */
    public function handle(Course $course, array $items, int $submittedVersion): void
    {
        $nodes = $this->resolve($course, $items);

        $this->assertReady($nodes);

        DB::transaction(function () use ($course, $nodes, $submittedVersion): void {
            // Claimed inside the transaction, and the claim IS the bump. The
            // FormRequest's earlier comparison is advisory: it reads a loaded model,
            // so two concurrent publishes both pass it. A conditional UPDATE is
            // what makes one of them 409 — see StructureVersion.
            StructureVersion::claim($course, $submittedVersion);

            foreach ($nodes as [$node, $status]) {
                $node->forceFill(['status' => $status])->save();

                // One line per node, not one per batch. FR-056 asks for the record
                // to sit on the item concerned, and a single line naming eleven
                // uuids is a record nobody can read from the item's own history.
                $this->logActivity($status->value, $node);
            }

            // Publishing changes the published set, so a recompute that only ran
            // on create/update/delete would be stale from the first publish on.
            CourseDuration::recompute($course);
        });

        // Announced after the commit, so a subscriber cannot read a tree that is
        // still half-written. An exam item entering a student's denominator is a
        // fact Learning has to act on — see ExamItemOpened.
        foreach ($nodes as [$node, $status]) {
            if ($node instanceof Lesson
                && $status === ContentStatus::Published
                && $node->type === LessonType::Exam->value) {
                event(new ExamItemOpened($node));
            }
        }

        // And the fact that covers everyone else: the denominator moved.
        //
        // `progress_pct` is written when a LESSON is completed and at no other
        // moment, so before this event every stored percentage in the course went
        // stale the instant a batch was published — the teacher was shown a drop
        // in the preview that never reached a single student's screen (`FR-051`).
        // Fired last, after the exam items, so the backfill has already credited
        // whoever it credits and the resync corrects one consistent picture.
        event(new CourseStructureChanged($course));
    }

    /**
     * Refuses a node that is not ready to be opened to students (`FR-022`).
     *
     * Public and separate because the impact preview must refuse the SAME batch
     * for the same reason: a preview that only counted items would answer "3
     * items, 12 students affected", and the publish it invited would then 422 on
     * an article with an empty body. The preview's promise is that pressing
     * confirm does what it just described — including doing nothing at all.
     *
     * @param  list<array{0: Model, 1: ContentStatus}>  $nodes
     */
    public function assertReady(array $nodes): void
    {
        foreach ($nodes as [$node, $status]) {
            if ($node instanceof Lesson && $status === ContentStatus::Published) {
                PublishReadiness::assertPublishable($node);
            }
        }
    }

    /**
     * Pairs each submitted uuid with the node it names and the state asked for.
     *
     * Public and separate from the write because the impact preview must run the
     * SAME resolution the publish runs (SC-018) — an estimate computed from its
     * own reading of the payload is a second implementation that drifts.
     *
     * @param  list<array{uuid: string, status: string}>  $items
     * @return list<array{0: Model, 1: ContentStatus}>
     */
    public function resolve(Course $course, array $items): array
    {
        // No column list here, deliberately — and it was tried.
        //
        // `PublishReadiness::satisfies` decides whether an article may be published
        // by reading `content` and trimming it, so a select that omitted the body
        // would report every article as empty and refuse to publish a finished one.
        // The heavy read is the price of checking that the content exists before
        // opening it to students, which is this Action's whole job (FR-022). The
        // tree READ is where a column list is free, because nothing there emits a
        // body — see SectionController::tree.
        $sections = Section::query()->where('course_id', $course->getKey())->get()->keyBy('uuid');
        $chapters = Chapter::query()->where('course_id', $course->getKey())->get()->keyBy('uuid');
        $lessons = Lesson::query()->where('course_id', $course->getKey())->get()->keyBy('uuid');

        $resolved = [];

        foreach ($items as $item) {
            $uuid = $item['uuid'];

            /** @var Model|null $node */
            $node = $sections->get($uuid) ?? $chapters->get($uuid) ?? $lessons->get($uuid);

            if ($node === null) {
                // 404, not 422 (contract §5, FR-059). The uuid may well name a
                // real node — of another course. Through this route it does not
                // exist, and answering anything more specific turns the endpoint
                // into a way to test whether a given uuid is a node at all.
                abort(404, 'أحد العناصر المحدَّدة لا ينتمي إلى هذا الكورس. أعد تحميل الشجرة.');
            }

            $resolved[] = [$node, ContentStatus::from($item['status'])];
        }

        return $resolved;
    }
}
