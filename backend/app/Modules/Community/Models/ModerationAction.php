<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Community\ModerationActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One moderation decision — WORKSPACE-owned (layer 2).
 *
 * ⚠️ APPEND-ONLY, ENFORCED ON THE MODEL AND NOT ONLY IN THE ACTION. This table IS
 * the record `FR-021` asks for, so an edited reason or a deleted ban row erases
 * the fact and the reason together. `LedgerEntry` guards itself the same way and
 * for the same reason: an Action is one caller, a model is every caller.
 *
 * @property int $workspace_id
 * @property int|null $actor_user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property ModerationVerdict $verdict
 * @property string|null $reason
 * @property CarbonImmutable|null $expires_at
 */
class ModerationAction extends BaseModel
{
    /** @use HasFactory<ModerationActionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    public const SUBJECT_USER = 'user';

    public const SUBJECT_MESSAGE = 'message';

    /**
     * A student's public review of their teacher (010 · FR-034).
     *
     * ⚠️ THE SAME TABLE ON PURPOSE. A report against a review is the same act as a
     * report against a message — someone read something and says it should not
     * stand — and a second table would need a second queue and a second screen
     * that nobody remembers to open.
     */
    public const SUBJECT_REVIEW = 'review';

    protected $fillable = [
        'workspace_id',
        'actor_user_id',
        'subject_type',
        'subject_id',
        'verdict',
        'reason',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('سجلّ الإشراف لا يُعدَّل: القرار الجديد صفٌّ جديد.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('سجلّ الإشراف لا يُحذف: رفعُ الحظر صفٌّ جديد.');
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'verdict' => ModerationVerdict::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
