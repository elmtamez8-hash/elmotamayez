<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Resources\CourseResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ⚠️ NO CREATE BUTTON. A course is created by its teacher on the site, through
 * `CreateCourse` — which sets the currency from the platform setting, refuses a
 * visibility the creator may not choose, and seeds what a new course needs. The
 * modal `CreateAction` that stood here was Filament's `new Course($data)`: it
 * stamped the OFFICER's own workspace (`BelongsToWorkspace` fills it from their
 * `last_workspace_id`) and reached none of that.
 */
class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;
}
