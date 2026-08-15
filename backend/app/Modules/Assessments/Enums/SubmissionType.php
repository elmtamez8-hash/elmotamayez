<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

/**
 * What an assignment accepts as a submission (FR-044).
 *
 * `Questions` points at an exam built from bank items — an exam with no place in
 * any grade reading, which is why 008 adds no fourth "result" entity: the
 * attempt already holds a score and the submission already holds one.
 */
enum SubmissionType: string
{
    case Text = 'text';
    case File = 'file';
    case Questions = 'questions';
}
