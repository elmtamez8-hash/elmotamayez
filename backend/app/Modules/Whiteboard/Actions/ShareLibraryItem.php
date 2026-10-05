<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Models\BoardLibraryItem;
use App\Modules\Whiteboard\Support\SceneValidator;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Shared\Actions\Action;

/**
 * «شارك مع الأكاديمية»: a shape joins the workspace's shared board library.
 *
 * The elements pass the page's own rules (no embeds, no inline bytes, safe
 * links, pictures only as templates) and a size cap: every teacher's board
 * downloads the whole library when it opens.
 */
class ShareLibraryItem extends Action
{
    public const MAX_ELEMENTS = 300;

    public const MAX_BYTES = 200_000;

    /** ponytail: a flat count per academy; per-teacher quotas if one teacher fills it. */
    public const MAX_ITEMS = 500;

    public function __construct(private readonly SceneValidator $validator) {}

    /**
     * @param  array<mixed>  $elements
     *
     * @throws WhiteboardRefusal
     */
    public function handle(User $user, int $workspaceId, string $name, array $elements): BoardLibraryItem
    {
        $this->validator->validateLibraryElements($elements);

        $json = json_encode(array_values($elements), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            throw new WhiteboardRefusal('library_item_too_large');
        }

        if (BoardLibraryItem::query()->where('workspace_id', $workspaceId)->count() >= self::MAX_ITEMS) {
            throw new WhiteboardRefusal('library_full');
        }

        return BoardLibraryItem::query()->create([
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->getKey(),
            'name' => $name,
            'elements' => $json,
        ]);
    }
}
