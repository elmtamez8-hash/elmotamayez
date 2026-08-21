<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Modules\Courses\Listeners\SyncLessonDurationFromAsset;
use App\Modules\Courses\Support\CoursesPersonalData;
use App\Modules\Media\Events\MediaAssetReady;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class CoursesServiceProvider extends Module
{
    protected string $name = 'Courses';

    public function boot(): void
    {
        parent::boot();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([CoursesPersonalData::class], 'compliance.personal_data');

        // Media does not know lessons exist. It announces that bytes finished
        // processing; who cares is the subscriber's business.
        Event::listen(MediaAssetReady::class, SyncLessonDurationFromAsset::class);
    }
}
