<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Whiteboard;

use App\Modules\Whiteboard\Models\BoardLibraryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardLibraryItem>
 *
 * The workspace and the sharer are given by the test, which knows who should
 * see the item.
 */
class BoardLibraryItemFactory extends Factory
{
    protected $model = BoardLibraryItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'مثلث قائم',
            'elements' => '[{"id":"a","type":"line","x":0,"y":0,"points":[[0,0],[100,0],[0,100],[0,0]]}]',
        ];
    }
}
