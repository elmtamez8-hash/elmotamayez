<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Exceptions;

use DomainException;

/**
 * A study room said no, and said which no it was (spec 012 · US3).
 *
 * ⚠️ ONE CLASS CARRYING A CODE, NOT THREE CLASSES. The three refusals differ
 * only in the sentence and the status, and the controller has to map every one of
 * them — three classes means three catch arms whose ORDER matters, which is the
 * defect `AdaptiveController`'s docblock already records for
 * `FeatureDisabledException` sitting behind `DomainException`.
 *
 * ⚠️ AND THE CODE TRAVELS TO THE CLIENT WHILE THE REASON DOES NOT. `not_eligible`
 * never names the exam or the course that made it true: FR-017's refusal is about
 * ENTITLEMENT, and a refusal that explains itself is an oracle over another
 * student's bank.
 */
class StudyRoomRefusal extends DomainException
{
    private function __construct(
        string $message,
        public readonly string $refusalCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** FR-016 — derived from the clock, so it needs no job to have happened. */
    public static function closed(): self
    {
        return new self('انتهت هذه الغرفة.', 'room_closed', 409);
    }

    public static function full(): self
    {
        return new self('اكتمل عدد المشاركين في هذه الغرفة.', 'room_full', 409);
    }

    /** FR-017. Deliberately says nothing about WHICH question was out of reach. */
    public static function notEligible(): self
    {
        return new self('لا يمكنك الانضمام إلى هذه الغرفة.', 'not_eligible', 403);
    }
}
