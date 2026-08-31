<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

/**
 * A student has mastered a concept (spec 012 · FR-003).
 *
 * ⚠️ IT HAS A WRITER, AND SAYING SO MATTERS. `ClassSessionStatus::Interrupted`
 * shipped with three readers and no writer at all — a requirement everybody
 * believed was implemented. This one is raised by `AnswerAdaptiveStep` at the
 * moment the mastery row is written, which is the only place mastery is decided.
 *
 * Ids rather than models, on `MistakeResolved`'s precedent: the fact is about a
 * (student, concept, teacher) triple, and a listener that wants more can fetch
 * the one thing it needs rather than every listener paying for a hydrated model.
 */
class ConceptMastered
{
    public function __construct(
        public readonly int $studentId,
        public readonly int $conceptId,
        public readonly int $workspaceId,
        public readonly int $masteryId,
    ) {}
}
