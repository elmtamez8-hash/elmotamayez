<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\CourseDuration;
use App\Modules\Courses\Support\PublishReadiness;
use App\Shared\Actions\Action;
use DomainException;
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
    /**
     * @param  list<array{uuid: string, status: string}>  $items
     */
    public function handle(Course $course, array $items): void
    {
        $nodes = $this->resolve($course, $items);

        foreach ($nodes as [$node, $status]) {
            if ($node instanceof Lesson && $status === ContentStatus::Published) {
                PublishReadiness::assertPublishable($node);
            }
        }

        DB::transaction(function () use ($course, $nodes): void {
            foreach ($nodes as [$node, $status]) {
                $node->forceFill(['status' => $status])->save();
            }

            $course->increment('structure_version');

            // Publishing changes the published set, so a recompute that only ran
            // on create/update/delete would be stale from the first publish on.
            CourseDuration::recompute($course);
        });
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
        $sections = Section::query()->where('course_id', $course->getKey())->get()->keyBy('uuid');
        $chapters = Chapter::query()->where('course_id', $course->getKey())->get()->keyBy('uuid');
        $lessons = Lesson::query()->where('course_id', $course->getKey())->get()->keyBy('uuid');

        $resolved = [];

        foreach ($items as $item) {
            $uuid = $item['uuid'];

            /** @var Model|null $node */
            $node = $sections->get($uuid) ?? $chapters->get($uuid) ?? $lessons->get($uuid);

            if ($node === null) {
                // 404 rather than 422: the uuid may well be a real node — of
                // someone else's course. Saying "not in this course" is the
                // whole answer, and saying more is an identity probe (FR-059).
                throw new DomainException('أحد العناصر المحدَّدة لا ينتمي إلى هذا الكورس. أعد تحميل الشجرة.');
            }

            $resolved[] = [$node, ContentStatus::from($item['status'])];
        }

        return $resolved;
    }
}
