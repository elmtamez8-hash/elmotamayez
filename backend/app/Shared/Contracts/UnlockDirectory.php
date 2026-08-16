<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a student has earned the session after the one they just had.
 *
 * Exists so LiveSessions can ask without reaching into Assessments' models,
 * which Constitution III forbids — the same arrow as `AccountStanding`, where
 * 005 asks Payments about withholding. Assessments owns the rule, the homework
 * and the exemption, and binds the implementation; LiveSessions depends only on
 * this interface.
 *
 * ⚠️ THE READ IS BULK BY DESIGN. A student's timetable is twenty sessions, and a
 * per-session question inside a Resource is an N+1 by construction — the defect
 * `QueryBudgetTest` already exists to catch once. The singular form below is for
 * the one door being opened, not for a list.
 */
interface UnlockDirectory
{
    /**
     * Why this student may not open this session yet, or null when they may.
     *
     * A sentence, not a boolean: FR-038 forbids refusing without saying what is
     * missing. A block with no stated cause turns a motivation feature into an
     * outage the student emails their teacher about.
     */
    public function refusalFor(User $student, int $classSessionId): ?string;

    /**
     * The same question for a list, in a fixed number of queries.
     *
     * @param  list<int>  $classSessionIds
     * @return array<int, string|null> keyed by session id; null means open
     */
    public function refusalsFor(User $student, array $classSessionIds): array;

    /**
     * The same verdict, spelled out for a screen (FR-038 · T174).
     *
     * ⚠️ A PLAIN ARRAY, NOT AN ASSESSMENTS OBJECT. The contract is what keeps
     * LiveSessions from importing this module's classes; handing back a typed
     * verdict here would make the interface a re-export of the implementation.
     *
     * `rule_scope` is in the payload because PRECEDENCE IS INVISIBLE OTHERWISE:
     * a teacher with a workspace default and a course override cannot tell which
     * of the two refused, and "why is this shut" becomes a support question
     * neither of them can answer from the screen.
     *
     * @return array{open: bool, reason: string|null, missing: list<string>, rule_scope: string, exempt: bool}
     */
    public function explain(User $student, int $classSessionId): array;

    /**
     * Stamp a page of sessions with their verdicts, in a fixed number of queries.
     *
     * ⚠️ ON THE CONTRACT RATHER THAN AS AN ASSESSMENTS CLASS THE CALLER IMPORTS.
     * The consumer is a LiveSessions controller, and Constitution III forbids it
     * reaching into another module's classes — a helper imported directly would
     * be the coupling this interface exists to prevent, one indirection deeper.
     * The signature carries only Illuminate types for the same reason.
     *
     * Sets `unlock_open`, `unlock_reason` and `unlock_missing` on each model.
     * They are not columns: the same session is open for one student and shut
     * for another, so storing them would need a sweep per student per session —
     * and the sweep would be wrong the moment somebody handed their homework in.
     *
     * @param  iterable<Model>  $sessions
     */
    public function stamp(iterable $sessions, User $student): void;
}
