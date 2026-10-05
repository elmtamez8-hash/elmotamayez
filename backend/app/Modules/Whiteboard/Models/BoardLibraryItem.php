<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Whiteboard\BoardLibraryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shape in the academy's shared board library: Excalidraw elements stored
 * as the JSON text that arrived (`BoardLibraryItemRules` checked it).
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string $elements
 */
class BoardLibraryItem extends BaseModel
{
    /** @use HasFactory<BoardLibraryItemFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'created_by_user_id',
        'name',
        'elements',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
