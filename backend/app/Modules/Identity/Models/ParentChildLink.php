<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not workspace-scoped — see the migration. A parent is a platform account with
 * no academy of their own, so BelongsToWorkspace here would scope by a column
 * that is null on every row.
 *
 * @property int $parent_id
 * @property int|null $child_id
 * @property string $child_name
 * @property int|null $child_age
 */
class ParentChildLink extends BaseModel
{
    use HasUuid;

    protected $table = 'parent_child_links';

    protected $fillable = [
        'parent_id',
        'child_id',
        'child_name',
        'child_age',
        'child_grade_level_slug',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['child_age' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(User::class, 'child_id');
    }
}
