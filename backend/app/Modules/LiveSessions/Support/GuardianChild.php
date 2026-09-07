<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\Request;

/**
 * «Is the child named in this request mine, and am I allowed to ask this?»
 *
 * The shape is `BillingController::childBalance()`'s, spelled once here because
 * this module asks it TWICE — for the timetable and for the register summary —
 * and two spellings of one question is how one answer reaches the screen and
 * another reaches the door.
 *
 * Three properties are load-bearing and each has cost this product something:
 *
 *  - ⚠️ **THE CHILD IS MATCHED INSIDE THE AUTHORISED LIST, never fetched by uuid
 *    and then checked.** `exists:users,uuid` answers a different question, and a
 *    bare uuid parameter is an identity probe: fetch first and the response
 *    comes back carrying a name whether or not the relation exists.
 *  - ⚠️ **403, NEVER 404, AND WITH NO MESSAGE OF ITS OWN.** Two different
 *    answers for «not your child» and «nobody» would tell an unauthorised
 *    reader that a uuid names a real person. The refusal is deliberately
 *    identical byte for byte.
 *  - ⚠️ **THE PERMISSION IS PART OF THE QUESTION.** A guardian entitled to
 *    attendance news but not to the timetable is the right relation with the
 *    wrong consent, and `childrenOf()` is what folds the two together.
 *
 * `422` for a missing `student` rather than a default: guessing a child when
 * several are linked shows a parent the wrong one with nothing saying so.
 */
final class GuardianChild
{
    public static function named(
        Request $request,
        User $guardian,
        GuardianDirectory $guardians,
        GuardianPermission $permission,
    ): User {
        $requested = $request->query('student');

        abort_unless(is_string($requested) && $requested !== '', 422);

        $child = $guardians->childrenOf($guardian, $permission)->firstWhere('uuid', $requested);

        abort_if($child === null, 403);

        return $child;
    }
}
