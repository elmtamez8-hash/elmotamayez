<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Http\Resources\CourseTreeResource;
use App\Modules\Courses\Models\Course;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Refuses a write computed against a tree that has since changed.
 *
 * Answered with 409 and the current version, not 422: nothing about the request
 * was malformed. The client's map is out of date, and the fix is to re-read the
 * tree — a different instruction to the user than "correct your input".
 *
 * Shared by reorder and publish because both are whole-tree writes sent from a
 * map the client drew earlier. Two copies of the comparison would eventually
 * disagree about which one 409s.
 */
final class StructureVersion
{
    /**
     * A fast, friendly 409 before any work is done — advisory only.
     *
     * Kept because a FormRequest is where the client gets the clearest answer, and
     * because refusing early avoids resolving a whole tree for a write that cannot
     * land. It is NOT the guarantee: this reads the loaded model, so two requests
     * can both pass it. {@see claim()} is what actually holds the line, inside the
     * Action's transaction.
     */
    public static function assertMatches(Course $course, int $submitted): void
    {
        if ($submitted !== (int) $course->structure_version) {
            self::conflict($course);
        }
    }

    /**
     * Claims the version and bumps it in ONE statement.
     *
     * This used to compare `$submitted` against the already-loaded model and leave
     * the bump to a later `increment()` in the Action — a read, then a write, with
     * the whole request in between. So the lost update it exists to prevent still
     * happened: two teachers both load the tree at 5, both send
     * `structure_version: 5`, both pass the comparison, the first writes its order
     * and bumps to 6, the second writes a different order and bumps to 7. Neither
     * ever sees a 409, and the first teacher's reorder is gone — which is a write
     * to ACCESS RIGHTS, since `accessTo` derives what a student may open from
     * these positions.
     *
     * A conditional UPDATE is the project's own idiom for exactly this, from the
     * seat claim: `WHERE structure_version = ?` makes the token the lock as well,
     * and zero affected rows IS the conflict. Never `count()` then write, and never
     * `lockForUpdate()`, which is a no-op on SQLite and so proves nothing locally
     * about the MySQL it runs on.
     *
     * Callers must run this INSIDE their transaction and must not increment the
     * column again — the claim is the bump.
     */
    public static function claim(Course $course, int $submitted): void
    {
        $claimed = DB::table('courses')
            ->where('id', $course->getKey())
            ->where('structure_version', $submitted)
            ->update(['structure_version' => DB::raw('structure_version + 1')]);

        if ($claimed === 1) {
            // Keep the in-memory model honest: callers read it afterwards to
            // report the new version, and a stale attribute would send the client
            // a token that is already spent.
            $course->setAttribute('structure_version', $submitted + 1)
                ->syncOriginalAttribute('structure_version');

            return;
        }

        self::conflict($course->fresh() ?? $course);
    }

    /**
     * The refusal carries the tree AS IT NOW IS, not just the new number.
     *
     * The editor's map is what went stale, and a version alone only tells it so;
     * it would then have to fetch the tree itself, and that second read is taken
     * at a different moment than the one that refused it — so the map it rebuilds
     * can already be stale again. Sending the tree with the refusal makes the
     * answer and the state one response (contract §5, `FR-009`).
     */
    private static function conflict(Course $course): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'تغيّرت الشجرة منذ فتحتها. أعد تحميلها قبل الحفظ حتى لا يُدهس تعديل غيرك.',
            'structure_version' => $course->structure_version,
            'tree' => CourseTreeResource::for($course),
        ], 409));
    }
}
