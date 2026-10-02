<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Whiteboard;

use App\Modules\Whiteboard\Models\BoardLessonExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardLessonExport>
 *
 * The board and the lesson are always given by the test: both must sit in the
 * same workspace, and a factory that invented either would build a pair the
 * product can never produce.
 */
class BoardLessonExportFactory extends Factory
{
    protected $model = BoardLessonExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
