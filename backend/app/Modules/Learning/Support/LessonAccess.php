<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

/**
 * Whether the student may open this item, and — when not — why (FR-043).
 *
 * A bare `false` was enough while the only reason was "finish the previous
 * lesson", because the student could see that lesson right above the locked one
 * and work it out. An exam gate is not visible that way: the student sees a
 * closed item and has no way to learn that a quiz two positions back has to be
 * PASSED rather than merely sat, or that the attempt they abandoned halfway
 * does not count. A lock with no explanation is a support ticket.
 *
 * The reason travels as a `code` beside the sentence. The sentence is what the
 * student reads; the code is what a test asserts on and what a screen switches
 * on — asserting on Arabic prose means the message can never be reworded.
 */
final class LessonAccess
{
    public const SEQUENCE = 'sequence';

    public const EXAM_ATTEMPT = 'exam_attempt';

    public const EXAM_PASS = 'exam_pass';

    public const NOT_ENROLLED = 'not_enrolled';

    /**
     * The item, or a parent of it, is a draft or archived.
     *
     * Worded to the student as "not available", with no hint of what is behind it:
     * that a teacher has an unfinished lesson at this position is the teacher's
     * business, and "coming soon" invites the student to keep trying the URL.
     */
    public const NOT_VISIBLE = 'not_visible';

    public const INACTIVE = 'inactive';

    /**
     * A session recording, opened by someone who held no seat in that session.
     *
     * The third entitlement route (FR-030): enrolment in the course is not
     * enough, because the hour was sold by the seat. Distinct from SEQUENCE
     * because there is nothing to go and finish — the answer is not "later".
     */
    public const NO_SEAT = 'no_seat';

    /**
     * The course runs in groups and the student is in none of them (FR-028أ).
     *
     * ⚠️ IT IS ONLY EVER RAISED WHILE THERE IS A GROUP THEY COULD JOIN. The
     * moment the last joinable one fills, closes or is archived, this reason
     * disappears and the whole curriculum opens — see `CohortGate`. A condition
     * no action of the student's can satisfy is a permanent lock on content they
     * have already paid for.
     *
     * Distinct from SEQUENCE because what has to happen is not finishing
     * anything, and distinct from NO_SEAT because there IS something they can
     * do: NO_SEAT means "not yours", this means "pick a group first".
     */
    public const NO_COHORT = 'no_cohort';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        /** What the student has to go and do — the item that unlocks this one. */
        public readonly ?string $blockedByTitle = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $code, string $message, ?string $blockedByTitle = null): self
    {
        return new self(false, $code, $message, $blockedByTitle);
    }
}
