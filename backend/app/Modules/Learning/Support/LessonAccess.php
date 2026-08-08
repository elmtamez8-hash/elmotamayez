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
