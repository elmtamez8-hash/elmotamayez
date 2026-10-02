<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Whiteboard;

use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardScene;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardPage>
 *
 * The scene's frame carries THIS page's uuid, so the uuid is chosen here and
 * passed to the model — `HasUuid` would otherwise mint a different one after.
 */
class BoardPageFactory extends Factory
{
    protected $model = BoardPage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();
        $scene = BoardScene::blank($uuid);

        return [
            'uuid' => $uuid,
            'position' => 1,
            'scene' => $scene,
            'scene_bytes' => strlen($scene),
        ];
    }
}
