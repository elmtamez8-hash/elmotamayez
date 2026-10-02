<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Whiteboard;

use App\Models\User;
use App\Modules\Whiteboard\Models\BoardImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardImport>
 *
 * Born `uploading` (the database default). A test that needs another status sets
 * it with `forceFill`, the way the conditional UPDATEs would have.
 */
class BoardImportFactory extends Factory
{
    protected $model = BoardImport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }
}
