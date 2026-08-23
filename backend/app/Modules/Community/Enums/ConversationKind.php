<?php

declare(strict_types=1);

namespace App\Modules\Community\Enums;

/**
 * What a conversation is attached to.
 *
 * ⚠️ THERE IS NO `group` MEMBER, AND ITS ABSENCE IS DECLARED RATHER THAN
 * OVERLOOKED. `FR-004` and `FR-042` mention "groups"; no such entity exists
 * anywhere in this repository, and building one reaches into 005, 006 and 008.
 * The scope of this phase is the course, the session and all students (ق-٥/ت-٣);
 * study groups wait for 012.
 */
enum ConversationKind: string
{
    /** One student and their teacher's side. Exactly one per student per workspace. */
    case Private = 'private';

    /** The public room under one live session. */
    case Session = 'session';

    /** The public room under one recorded lesson. */
    case Lesson = 'lesson';

    /**
     * Whether everyone entitled to the parent may read and write.
     *
     * The private kind is the exception: membership is the explicit participant
     * list, not entitlement to a course.
     */
    public function isPublic(): bool
    {
        return $this !== self::Private;
    }
}
