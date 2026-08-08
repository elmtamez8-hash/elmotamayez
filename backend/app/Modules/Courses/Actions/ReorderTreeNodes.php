<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\StructureVersion;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the position of one sibling group.
 *
 * **This is a write to access rights, not to a display order.**
 * `Enrollment::canAccessLesson()` decides what a student may open from the
 * triplet of section, chapter and lesson positions — so moving a lesson up
 * changes who can reach what, immediately.
 *
 * The caller sends the COMPLETE ordered list of sibling uuids. That is the whole
 * design: a duplicate position cannot be expressed, a missing sibling is
 * detectable, and there is no server-side "shift everything after position 3"
 * arithmetic to get wrong. The alternative — "move this node to index 3" — needs
 * the server to compute the rest and leaves a window where the tree can be read
 * half-renumbered.
 *
 * Written in one transaction, through a temporary offset. Assigning final
 * positions directly would violate the unique(parent, order) index halfway
 * through any swap: giving node B position 0 while node A still holds it fails
 * before the statement that would have fixed it.
 */
class ReorderTreeNodes extends Action
{
    use LogsActivity;

    /**
     * @template TNode of Model
     *
     * @param  Builder<TNode>  $siblings  every node in the group, unordered
     * @param  list<string>  $orderedUuids  the group's new order, complete
     */
    public function handle(Course $course, Builder $siblings, array $orderedUuids, int $submittedVersion): void
    {
        $existing = (clone $siblings)->pluck('id', 'uuid')->all();

        $this->assertCoversExactly(array_keys($existing), $orderedUuids);

        DB::transaction(function () use ($course, $siblings, $existing, $orderedUuids, $submittedVersion): void {
            $table = $siblings->getModel()->getTable();

            // Computed, never the constant 1000 it used to be.
            //
            // The parking pass must land outside the range any live row occupies,
            // and `HasSiblingOrder` allocates `max('order') + 1` with nothing
            // renumbering after a delete — so `max(order)` grows with the group's
            // LIFETIME create count, not its row count. A chapter where a teacher
            // created and removed a thousand items over a year holds ten rows with
            // orders past 1000, and parking the first at 1000 hit the row already
            // sitting there: a duplicate-key 500 with a raw SQL message, which the
            // project forbids showing at all.
            $offset = ((int) (clone $siblings)->max('order')) + 1;

            // Park every row above the range it will land in, so no intermediate
            // state collides with the unique index.
            foreach (array_values($existing) as $index => $id) {
                DB::table($table)->where('id', $id)->update(['order' => $offset + $index]);
            }

            foreach ($orderedUuids as $position => $uuid) {
                DB::table($table)->where('id', $existing[$uuid])->update(['order' => $position]);
            }

            // The claim, not an increment — inside this transaction, so a second
            // reorder computed against the same version 409s instead of silently
            // overwriting the first. Which matters more here than anywhere: these
            // positions are what `Enrollment::accessTo` derives a student's access
            // from.
            StructureVersion::claim($course, $submittedVersion);

            // The subject is the COURSE, not a node: a reorder is one fact about
            // one sibling group, and writing it once per moved row would bury the
            // shape of what happened under its mechanics. The level is named
            // because a course, a section and a chapter all reorder through here.
            $this->logActivity('reordered', $course, [
                'level' => $table,
                'count' => count($orderedUuids),
            ]);
        });
    }

    /**
     * @param  list<string>  $actual
     * @param  list<string>  $submitted
     */
    private function assertCoversExactly(array $actual, array $submitted): void
    {
        if (count($submitted) !== count(array_unique($submitted))) {
            throw new DomainException('قائمة الترتيب تحوي عنصراً مكرّراً.');
        }

        if (array_diff($actual, $submitted) !== [] || array_diff($submitted, $actual) !== []) {
            // Both directions, and one message: a list that is missing a sibling
            // and a list that names a stranger are the same mistake from the
            // client's side — it sent a layout that is not this tree's.
            throw new DomainException('قائمة الترتيب لا تطابق عناصر هذا المستوى. أعد تحميل الشجرة.');
        }
    }
}
