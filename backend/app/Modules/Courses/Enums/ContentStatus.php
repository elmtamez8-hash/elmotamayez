<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

/**
 * Where a node of the course tree is in its life.
 *
 * Three states, not a boolean, because archiving is required: a lesson any
 * student has progress on may never be hard-deleted (FR-007), so "gone from the
 * course" has to be a state the row can hold. `is_published` plus an
 * `archived_at` column would encode one lifecycle in two places, and every query
 * would have to read both — one forgotten branch shows an archived lesson.
 *
 * Draft is the default, and that is a precondition rather than a preference:
 * the progress denominator counts published items, so a half-written lesson
 * saved into a live course would otherwise drop every enrolled student's
 * percentage the moment it is created.
 */
enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودّة',
            self::Published => 'منشور',
            self::Archived => 'مؤرشف',
        };
    }

    /**
     * Whether this node may be shown to a student AT ALL.
     *
     * Only half the answer: a published lesson inside a draft section is still
     * hidden (FR-028). The chain is resolved in Lesson::visibleToStudents().
     */
    public function isVisibleToStudents(): bool
    {
        return $this === self::Published;
    }
}
