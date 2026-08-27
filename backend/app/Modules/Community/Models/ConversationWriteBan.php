<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\Carbon;
use Database\Factories\Modules\Community\ConversationWriteBanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One person, one thread, until a stated time (FR-047).
 *
 * @property Carbon|null $expires_at
 * @property Carbon|null $lifted_at
 */
class ConversationWriteBan extends BaseModel
{
    /** @use HasFactory<ConversationWriteBanFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'user_id',
        'issued_by',
        'reason',
        'expires_at',
    ];

    /*
    | ⚠️ `lifted_at` AND `lifted_by` ARE NOT FILLABLE. Lifting is a decision made
    | by one Action, and mass-assignable the pair becomes a second way to end a
    | ban from outside it — the `locked_at` reasoning, and `captured_order_id`'s
    | before that.
    */

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }
}
