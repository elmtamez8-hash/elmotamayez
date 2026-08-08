<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
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
    /**
     * @template TNode of Model
     *
     * @param  Builder<TNode>  $siblings  every node in the group, unordered
     * @param  list<string>  $orderedUuids  the group's new order, complete
     */
    public function handle(Course $course, Builder $siblings, array $orderedUuids): void
    {
        $existing = (clone $siblings)->pluck('id', 'uuid')->all();

        $this->assertCoversExactly(array_keys($existing), $orderedUuids);

        DB::transaction(function () use ($course, $siblings, $existing, $orderedUuids): void {
            $table = $siblings->getModel()->getTable();
            $offset = 1_000;

            // Park every row above the range it will land in, so no intermediate
            // state collides with the unique index.
            foreach (array_values($existing) as $index => $id) {
                DB::table($table)->where('id', $id)->update(['order' => $offset + $index]);
            }

            foreach ($orderedUuids as $position => $uuid) {
                DB::table($table)->where('id', $existing[$uuid])->update(['order' => $position]);
            }

            $course->increment('structure_version');
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
