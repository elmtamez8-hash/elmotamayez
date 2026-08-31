<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Exceptions;

use RuntimeException;

/**
 * A concurrent join won the unique index. Internal, and never reaches HTTP.
 *
 * ⚠️ IT EXISTS SO THE TRANSACTION ROLLS BACK. `JoinStudyRoom` writes the attempt
 * before it can claim the participation row — `attempt_id` is NOT NULL — so a
 * loser that simply RETURNED the winner's row from inside `DB::transaction()`
 * would COMMIT its own attempt: an orphan `in_progress` paper with no participant
 * pointing at it, no sweep, and nothing that would ever notice. Throwing is what
 * unwinds it; the caller catches, re-reads outside the transaction, and answers
 * with the seat that actually exists.
 *
 * ⚠️ AND IT IS ITS OWN CLASS RATHER THAN A `DomainException`. `StudyRoomRefusal`
 * extends that, and a catch wide enough to swallow this one would swallow
 * `room_full` — turning a refusal the student must see into a silent success.
 *
 * ⚠️ AND SQLITE CANNOT REPRODUCE THE RACE, which is precisely why the rollback is
 * written down here rather than left to a test to discover.
 */
class StudyRoomSeatTaken extends RuntimeException {}
