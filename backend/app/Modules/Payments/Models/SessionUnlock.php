<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ٠٣٥ — «وافقتُ على خصمِ حصّةٍ لأفتحَ محتوى تلك الحصّة».
 *
 * A bridge row, guarded exactly as CreditHold is.
 *
 * ⚠️ `reason` IS NOT DECORATION. Three of its four values write a row costing
 * ZERO credits — the subscription seat, the student removed from the room, and
 * the charged absence — and a zero row with no reason is indistinguishable from
 * a defect to whoever reads the table a month later. Same rule the ledger
 * already follows for its own zero-value entries.
 *
 * @property int $credits_charged
 * @property string $reason
 * @property CarbonInterface $consented_at
 * @property-read Workspace $workspace workspace_id is NOT NULL
 */
class SessionUnlock extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    /** The student agreed to spend a credit. */
    public const REASON_CONSENT = 'consent';

    /** Covered by a subscription — no credit to spend (research §R11). */
    public const REASON_SUBSCRIPTION = 'subscription';

    /** Removed by the teacher mid-lesson: the hour was taken from them. */
    public const REASON_REMOVED_FROM_ROOM = 'removed_from_room';

    /** The silent no-show was charged, so the content is theirs already. */
    public const REASON_CHARGED_ABSENCE = 'charged_absence';

    protected $fillable = [
        'workspace_id',
        'student_user_id',
        'class_session_id',
        'credits_charged',
        'reason',
        'credit_transaction_id',
        'consented_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'credits_charged' => 'integer',
            'consented_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<CreditTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class, 'credit_transaction_id');
    }
}
