<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssistantScope> */
class AssistantScopeFactory extends Factory
{
    protected $model = AssistantScope::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assistant_assignment_id' => AssistantAssignment::factory(),
            'course_id' => Course::factory(),
        ];
    }
}
