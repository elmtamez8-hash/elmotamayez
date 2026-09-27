<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Exceptions;

use DomainException;

/**
 * An exam somebody has sat cannot be deleted.
 *
 * Thrown from `Exam::booted()`'s `deleting` hook — the one place every door
 * reaches. A `DomainException`, so the API's global handler answers it as a 422
 * carrying this Arabic sentence, exactly as `CourseDeletionRefused` does.
 */
class ExamDeletionRefused extends DomainException {}
