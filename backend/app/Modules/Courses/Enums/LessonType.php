<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

enum LessonType: string
{
    case Video = 'video';
    case Pdf = 'pdf';
    case Article = 'article';
    case File = 'file';
}
