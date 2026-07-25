<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
