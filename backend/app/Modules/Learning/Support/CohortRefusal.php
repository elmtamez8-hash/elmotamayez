<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use DomainException;

/**
 * A refusal a student can act on, carrying a machine code beside the sentence.
 *
 * The sentence is what the student reads; the code is what a screen switches on
 * and what a test asserts against — asserting on Arabic prose means the wording
 * can never be improved. Same division `LessonAccess` already draws.
 *
 * ⚠️ `not_enrolled` IS A `403`, NOT A `422`. Every other code here describes a
 * state of the group; that one describes the reader's standing, and answering it
 * as a validation error puts «هذه المجموعة مكتملة»-shaped text where «this is
 * not yours» belongs.
 */
class CohortRefusal extends DomainException
{
    public function __construct(
        public readonly string $refusalCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function full(): self
    {
        return new self('cohort_full', 'اكتملت مقاعد هذه المجموعة.');
    }

    public static function alreadyMember(): self
    {
        return new self('already_member', 'أنت في مجموعة من هذا الكورس بالفعل — قدِّم طلب انتقال.');
    }

    public static function requestPending(): self
    {
        return new self('request_pending', 'لديك طلب انتقال معلَّق في هذا الكورس.');
    }

    public static function sameCohort(): self
    {
        return new self('same_cohort', 'أنت في هذه المجموعة بالفعل.');
    }

    public static function closed(): self
    {
        return new self('cohort_closed', 'هذه المجموعة غير مفتوحة للانضمام.');
    }

    /**
     * No live price reaches this group, so nobody can be sold a place in it
     * (٠٣٦ · FR-012).
     *
     * ⚠️ A SEPARATE CODE FROM `cohort_closed`, AND THE SENTENCES DIFFER FOR THE
     * SAME REASON. «مغلقة» describes a decision the teacher made about the door;
     * this describes a plan that has not been priced, switched off, or never
     * written — none of which the student can read, act on, or wait out in any
     * predictable way. Folding it into `cohort_closed` would send them to ask
     * about a door that is not the problem.
     *
     * ⚠️ AND IT CARRIES NO REASON. Whether the platform has priced a teacher's
     * plan is a commercial fact between the two of them.
     */
    public static function notListed(): self
    {
        return new self('cohort_not_listed', 'هذه المجموعة غير متاحة للانضمام الآن.');
    }

    public static function notEnrolled(): self
    {
        return new self('not_enrolled', 'لست مسجَّلاً في هذا الكورس.', 403);
    }

    public static function noMembership(): self
    {
        return new self('no_membership', 'لست في أي مجموعة من هذا الكورس بعد.');
    }
}
