<?php

declare(strict_types=1);

namespace App\Modules\Community\Enums;

/**
 * What a conversation is attached to.
 *
 * ⚠️ `Cohort` ARRIVED IN 021 ON THE EXACT CONDITION 010 SET FOR IT. That spec
 * refused to add it and said why: «no such entity exists anywhere in this
 * repository, and building one reaches into 005, 006 and 008» — an enum value
 * with no model behind it would have been a membership model, a screen and a
 * permission smuggled in as a string. All three now exist (`cohorts`,
 * `cohort_memberships`, the teacher's groups screen), so the value is backed by
 * something rather than promising it.
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
     * The thread of one cohort of one course (021 · FR-046).
     *
     * ⚠️ ITS TWO DOORS ARE DIFFERENT, WHICH NO OTHER KIND'S ARE. Reading is for
     * whoever was ever a member — a student who moved keeps the answers they were
     * given — while writing needs a membership that is open NOW. Every other
     * public kind answers one question for both doors; this one cannot, and
     * `ConversationPolicy` spells the pair out.
     */
    case Cohort = 'cohort';

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
