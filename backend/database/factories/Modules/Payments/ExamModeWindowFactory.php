<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Modules\Payments\Models\ExamModeWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamModeWindow>
 */
class ExamModeWindowFactory extends Factory
{
    protected $model = ExamModeWindow::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(7)->toDateString(),
        ];
    }
}
