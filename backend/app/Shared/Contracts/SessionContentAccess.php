<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use App\Shared\Data\SessionContentOffer;
use Illuminate\Database\Eloquent\Model;

/**
 * ٠٣٥ — السؤالُ الواحدُ الذي تسألُه أبوابُ محتوى الحصّةِ الستّة.
 *
 * ⚠️ A CONTRACT AND NOT A QUERY, because two automatic walls forbid
 * `Modules/Learning/` and `Modules/Community/` from naming `Payments` in ANY
 * form — an import or a quoted table name — and comments are stripped before
 * the scan, so a line explaining itself does not survive either
 * (`ContextIsolationTest`). Without the contract FR-008 cannot be implemented
 * at all. The third instance of this pattern in this repository, not an
 * invention: `UnlockDirectory`, `EnrollmentDirectory`, `SettlementClearance`.
 *
 * ⚠️ THE BULK FORM IS A CORRECTNESS CONDITION, NOT AN OPTIMISATION. Reading the
 * curriculum is the hottest read in the product and its cost is FLAT in the
 * size of the tree, guarded by a budget test that compares ten items against
 * two hundred. One question per item makes that hundreds of queries on a single
 * course, and the build is red in the same minute.
 *
 * ⚠️ AND THE VERDICT IS READ FROM THE PAYLOAD ON THE SCREEN, never re-derived
 * in TypeScript. Two spellings of one question is what made a paid-for
 * recording unopenable in 018, with the two doors disagreeing.
 */
interface SessionContentAccess
{
    /**
     * May this student open the files, the exam, the homework and the recording
     * of this session?
     *
     * True when they attended, when the seat was charged anyway (the silent
     * no-show has already paid for the hour), when a subscription covers it, or
     * when they consented to spend a credit.
     */
    public function mayOpenSessionContent(User $student, int $classSessionId): bool;

    /**
     * The same question for a page of sessions, in a fixed number of queries.
     *
     * @param  list<int>  $classSessionIds
     * @return list<int> the subset they may open — never the whole input
     */
    public function openableSessionIds(User $student, array $classSessionIds): array;

    /**
     * What pressing «افتح» would cost and what it would open.
     *
     * Null when there is nothing to offer: already open, never entitled, the
     * session was not delivered, or its material has been archived.
     */
    public function unlockOfferFor(User $student, int $classSessionId): ?SessionContentOffer;

    /**
     * Stamp a page of sessions with `content_locked` and `content_offer`.
     *
     * ⚠️ ON THE CONTRACT RATHER THAN AS A PAYMENTS CLASS THE CALLER IMPORTS,
     * for the reason `UnlockDirectory::stamp()` carries: the consumer is a
     * LiveSessions controller, and a helper imported directly is the coupling
     * this interface exists to prevent, one indirection deeper. The signature
     * therefore carries only Illuminate and root types.
     *
     * They are not columns: the same session is locked for one student and open
     * for another.
     *
     * @param  iterable<Model>  $sessions
     */
    public function stampAll(iterable $sessions, User $student): void;
}
