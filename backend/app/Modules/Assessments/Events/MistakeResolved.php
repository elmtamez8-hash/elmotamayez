<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

/**
 * A student answered correctly a question they had previously got wrong.
 *
 * ⚠️ IT HAS NO LISTENER TODAY, AND THAT IS DELIBERATE. Spec 009 names fixing a
 * mistake as a rewarded action, and the moment it happens is inside the grading
 * transaction — the hottest path in this module. Raising it now costs one query
 * per submission and means 009 adds a listener instead of editing the code that
 * marks every paper on the platform.
 *
 * It carries ids rather than models: the fact is about a (student, question,
 * teacher) triple, and a listener that wants the question can fetch the one it
 * needs instead of every listener paying for a hydrated model it may ignore.
 */
class MistakeResolved
{
    public function __construct(
        public readonly int $studentId,
        public readonly int $questionId,
        public readonly int $workspaceId,
    ) {}
}
