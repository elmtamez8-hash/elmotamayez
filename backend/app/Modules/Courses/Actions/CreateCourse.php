<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Models\User;
use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Str;

class CreateCourse extends Action
{
    use LogsActivity;

    public function handle(CreateCourseDTO $dto, User $creator): Course
    {
        $course = Course::create([
            'workspace_id' => app(WorkspaceContext::class)->id(),
            'title' => $dto->title,
            'slug' => $dto->slug ?? Str::slug($dto->title.'-'.Str::random(6)),
            'description' => $dto->description,
            'price_minor' => $dto->priceMinor,
            'currency' => $dto->currency,
            'status' => $dto->status,
            'visibility' => $dto->visibility,
            'is_sequential' => $dto->isSequential,
            'created_by' => $creator->getKey(),
        ]);

        $this->logActivity('created', $course, ['title' => $course->title]);

        return $course;
    }
}
