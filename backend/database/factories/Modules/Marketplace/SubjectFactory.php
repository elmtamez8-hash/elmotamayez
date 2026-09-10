<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Marketplace;

use App\Modules\Marketplace\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Subject> */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->randomElement(['الرياضيات', 'الفيزياء', 'الكيمياء', 'اللغة العربية', 'اللغة الإنجليزية']);

        return [
            'name' => $name,
            'slug' => Str::slug(fake()->unique()->word()),
            'icon' => 'academic-cap',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
