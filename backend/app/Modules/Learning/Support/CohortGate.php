<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Shared\Contracts\CohortDirectory;

/**
 * «اختر مجموعتَك للبدء» — the membership condition, and the valve that stops it
 * becoming a permanent lock.
 *
 * ⚠️ THE VALVE IS THE WHOLE DESIGN (FR-028ب). Membership is a condition on
 * opening content the student has ALREADY PAID FOR, so it may only ever hold
 * while there is something they can do about it. The moment the last joinable
 * group fills up, closes or is archived, {@see locks()} answers `false` and the
 * entire curriculum opens — instantly, with no nightly sweep and no operator.
 *
 * A condition no action of theirs can satisfy is a permanent lock on paid
 * content, which is the family of the worst defect this repository records — the
 * item that enters the denominator and can never be completed, capping every
 * enrolled student below 100% for ever.
 *
 * ⚠️ AND {@see locks()} DOES NOT ASK WHETHER THE COURSE HAS GROUPS AT ALL.
 * "A joinable group exists" already implies it, so the hot path — asked on every
 * lesson open — is one indexed existence query and, only when it fails, a
 * second. `required` costs a third and is read by the payload alone.
 */
final class CohortGate
{
    private function __construct(
        /** Does this course run in groups? (An archived group still means yes.) */
        public readonly bool $required,
        /**
         * Is the condition MET?
         *
         * ⚠️ NOT «HAS A MEMBERSHIP». On a course with no groups at all there is
         * nothing to satisfy, so this is `true` — a payload reading
         * `required: false, satisfied: false` invites a screen to tell a student
         * they are not in a group on a course that has none. Which group they
         * are actually in is `/courses/{course}/cohorts`, which is the question
         * the switcher asks.
         */
        public readonly bool $satisfied,
        /** Is there one they could join at this instant? */
        public readonly bool $joinableExists,
    ) {}

    /**
     * The predicate a locked lesson is decided by — cheap, and asked per request
     * rather than per row.
     */
    public static function locks(User $student, int $courseId): bool
    {
        $directory = app(CohortDirectory::class);

        if ($directory->hasOpenMembership($student, $courseId)) {
            return false;
        }

        return $directory->joinableCohortsExist($courseId);
    }

    /** The full block the course page reads, including the sentence. */
    public static function describe(User $student, int $courseId): self
    {
        $directory = app(CohortDirectory::class);

        $required = $directory->coursesWithCohorts([$courseId]) !== [];

        return new self(
            required: $required,
            satisfied: ! $required || $directory->hasOpenMembership($student, $courseId),
            joinableExists: $directory->joinableCohortsExist($courseId),
        );
    }

    /**
     * What the student is told.
     *
     * ⚠️ THE VALVE'S SENTENCE NAMES WHO TO ASK. "There is no group you can join"
     * with nothing after it is a dead end; the curriculum is open underneath it,
     * and the reader has to be told both halves or they will read an open course
     * as a broken one.
     */
    public function message(): ?string
    {
        if (! $this->required || $this->satisfied) {
            return null;
        }

        return $this->joinableExists
            ? 'اختر مجموعتك للبدء.'
            : 'لا توجد مجموعة مفتوحة للانضمام حالياً — راجع مدرّسك. المنهج مفتوح لك حتى ذلك الحين.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'required' => $this->required,
            'satisfied' => $this->satisfied,
            'joinable_exists' => $this->joinableExists,
            'message' => $this->message(),
        ];
    }
}
