<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Modules\Community\Enums\TermPolicy;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Community\BlockedTermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One filtered term and what happens when it matches — WORKSPACE-owned (layer 2).
 *
 * @property int $workspace_id
 * @property string $term
 * @property TermPolicy $policy
 */
class BlockedTerm extends BaseModel
{
    /** @use HasFactory<BlockedTermFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'term',
        'policy',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'policy' => TermPolicy::class,
        ];
    }
}
