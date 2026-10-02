<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Whiteboard;

use App\Models\User;
use App\Modules\Whiteboard\Enums\BoardBackground;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Board>
 *
 * ⚠️ `pages_count` ALWAYS EQUALS THE PAGES THAT EXIST. It is not fillable and
 * `BoardPageGate` subtracts only `WHERE pages_count >= ?`, so a factory that left
 * it at 0 beside real pages would make every delete test refuse for the wrong
 * reason. `withPages()` creates the pages and sets the count in one place.
 */
class BoardFactory extends Factory
{
    protected $model = Board::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'title' => 'سبّورة الحصة',
            'background' => BoardBackground::White,
        ];
    }

    public function withPages(int $count = 1): static
    {
        return $this->afterCreating(function (Board $board) use ($count): void {
            for ($position = 1; $position <= $count; $position++) {
                BoardPage::factory()->for($board)->create([
                    'workspace_id' => $board->workspace_id,
                    'position' => $position,
                ]);
            }
            $board->forceFill(['pages_count' => $count])->save();
        });
    }
}
