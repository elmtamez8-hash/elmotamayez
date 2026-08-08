<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Modules\Courses\Listeners\SyncLessonDurationFromAsset;
use App\Modules\Media\Events\MediaAssetReady;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class CoursesServiceProvider extends Module
{
    protected string $name = 'Courses';

    public function boot(): void
    {
        parent::boot();

        // Media does not know lessons exist. It announces that bytes finished
        // processing; who cares is the subscriber's business.
        Event::listen(MediaAssetReady::class, SyncLessonDurationFromAsset::class);
    }
}
