<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

/**
 * The six rankings (FR-020), and which of them cross a workspace boundary.
 *
 * A scope arrives from the client as `lesson:{uuid}`, `grade:{slug}` or bare
 * `platform`, and is stored as `lesson:{id}`, `grade:{slug}`, `platform`.
 *
 * ⚠️ THE WIRE FORM IS A UUID OR A SLUG, NEVER A SEQUENTIAL ID. Two reasons and
 * both matter: sequential ids violate the repository's `HasUuid` rule (routes and
 * payloads expose uuid, full stop), and they are also what makes the whole space
 * walkable in seconds — `subject:1`, `subject:2`, … is an enumeration of every
 * course and teacher on the platform with an activity signal attached.
 */
enum LeaderboardScope: string
{
    case Lesson = 'lesson';
    case Course = 'course';
    case Teacher = 'teacher';
    case Subject = 'subject';
    case Grade = 'grade';
    case Platform = 'platform';

    /**
     * Whether this ranking mixes students from different teachers.
     *
     * ⚠️ THE CROSS-WORKSPACE THREE ARE FOR STUDENTS ONLY; a teacher asking for one
     * gets 403 (Q9 · FR-020د · SC-023). A teacher holds no level band, so there is
     * no natural bound on what they would see — and the constitution forbids them
     * learning anything about a student with no active enrollment in their own
     * workspace, EVEN when the row is platform-owned. Opening these would hand
     * every teacher a weekly roster of their competitors' students: an abbreviated
     * name, a grade, a subject and proof of activity.
     */
    public function isCrossWorkspace(): bool
    {
        return match ($this) {
            self::Subject, self::Grade, self::Platform => true,
            self::Lesson, self::Course, self::Teacher => false,
        };
    }

    /** Whether the identifier is a slug rather than a uuid. */
    public function usesSlug(): bool
    {
        return $this === self::Grade;
    }

    public function needsIdentifier(): bool
    {
        return $this !== self::Platform;
    }

    /**
     * Split `subject:8f3e…` into its scope and its identifier.
     *
     * Returns null for anything unrecognised — the caller answers exactly as it
     * would for a scope the reader may not see, because a distinct reply for
     * "no such thing" is an oracle telling a prober what exists.
     *
     * @return array{0: self, 1: string}|null
     */
    public static function parse(string $raw): ?array
    {
        [$prefix, $identifier] = array_pad(explode(':', $raw, 2), 2, '');

        $scope = self::tryFrom($prefix);

        if ($scope === null) {
            return null;
        }

        if ($scope->needsIdentifier() && $identifier === '') {
            return null;
        }

        return [$scope, $identifier];
    }

    /** The stored `scope_key` for a resolved scope. */
    public function keyFor(string $identifier): string
    {
        return $this === self::Platform ? self::Platform->value : $this->value.':'.$identifier;
    }
}
