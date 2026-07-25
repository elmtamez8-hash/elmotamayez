<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';
}
