<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

enum CourseVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Hidden = 'hidden';
}
