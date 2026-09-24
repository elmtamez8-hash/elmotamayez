<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «أعتذر عن سبت هذا الأسبوع — هل يمكن الأحد ٦م؟» — and nothing has moved yet.
 *
 * @property string $status
 * @property Carbon $from_starts_at
 * @property Carbon $to_starts_at
 * @property Carbon|null $decided_at
 */
class SessionRescheduleRequest extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /**
     * Nobody answered before the moment it was about passed — the proposed hour
     * or the lesson's own start, whichever came first. Settled by
     * `ExpireSessionRescheduleRequestsJob`; it frees `srr_pending_unique` so the
     * lesson can be asked about again.
     */
    public const EXPIRED = 'expired';

    /*
    | ⚠️ `status` AND `pending_slot` ARE NOT FILLABLE. Both are written inside the
    | one statement that settles the request — the `captured_order_id` idiom.
    | Mass-assignable, each becomes a second way to claim a lock the transaction
    | owns: a PATCH body carrying `status: approved` would decide a request from
    | outside the Action that moves the lesson behind it.
    */
    protected $fillable = [
        'workspace_id',
        'class_session_id',
        'student_user_id',
        'from_starts_at',
        'to_starts_at',
        'student_reason',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'from_starts_at' => 'datetime',
            'to_starts_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ClassSession, $this> */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * @param  Builder<SessionRescheduleRequest>  $query
     * @return Builder<SessionRescheduleRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /**
     * Pending, and past the moment it is about: min(to_starts_at, from_starts_at).
     *
     * ⚠️ ONE SPELLING FOR BOTH READERS — the sweep and the ask. A request whose
     * proposed hour has gone holds `srr_pending_unique` and so refuses every new
     * ask about the same lesson with «هناك طلب قائم», about a request nobody can
     * approve any more. The OR is GROUPED: ungrouped it would OR at the top level
     * and match settled rows too.
     *
     * @param  Builder<SessionRescheduleRequest>  $query
     * @return Builder<SessionRescheduleRequest>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', self::PENDING)
            ->where(fn (Builder $q) => $q
                ->where('to_starts_at', '<=', $now)
                ->orWhere('from_starts_at', '<=', $now));
    }
}
