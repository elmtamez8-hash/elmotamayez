<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Support\DiscountResolver;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discount code (spec 011 · FR-010 · FR-012).
 *
 * ⚠️ NO `BelongsToWorkspace`, DELIBERATELY, AND THE VIOLATION IS RECORDED —
 * `plan.md › Complexity Tracking`. The constitution names coupons in the
 * workspace layer «with no exception»; FR-010 then moved authorship to the
 * PLATFORM, which makes this reference data of kind (ب), the same road
 * `credit_packages` travelled in 006.
 *
 * The trait cannot express it: a platform coupon is `workspace_id IS NULL`, and
 * the global scope adds `= X`, so every platform coupon — the ordinary case —
 * would vanish from every workspace in existence, silently. The guard is written
 * out instead, once, in {@see DiscountResolver}.
 *
 * ⚠️ `redemptions_count` IS NOT `$fillable`. It is claimed by a conditional
 * UPDATE carrying the cap predicate — the seat idiom — and a mass-assignable
 * counter is a second way to move it from outside the transaction that owns it.
 * Precedent: `captured_order_id`.
 *
 * @property string $code
 * @property int|null $workspace_id
 * @property CouponScope|null $scope_type
 * @property string|null $scope_uuid
 * @property CouponValueKind $value_kind
 * @property int $value
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_redemptions
 * @property int $redemptions_count
 * @property bool $is_active
 */
class Coupon extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'code',
        'workspace_id',
        'scope_type',
        'scope_uuid',
        'value_kind',
        'value',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'is_active',
        'created_by_user_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'scope_type' => CouponScope::class,
            'value_kind' => CouponValueKind::class,
            'value' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The one spelling of a code (T060).
     *
     * ⚠️ NORMALISED IN PHP, NEVER WITH `UPPER()` IN SQL. A function around the
     * column throws away the index on the single path whose rate limit exists
     * *because* it is guessed at — and the two engines disagree about the
     * alternative: MySQL's default collation matches case-insensitively while
     * SQLite does not, so leaving it to the database passes locally and proves
     * the opposite of what it claims about production.
     *
     * Whitespace goes too. A code pasted out of a poster arrives with a trailing
     * space more often than it arrives clean, and refusing that is a support
     * ticket about a coupon that is working perfectly.
     */
    public static function normaliseCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Whether the clock allows this coupon right now.
     *
     * Both bounds are nullable and both are inclusive of the whole final moment,
     * because the columns are TIMESTAMPS. A `date` compared with `<=` binds
     * midnight and kills a coupon on the morning of its own last day — the
     * boundary that has cost this repository three separate fixes.
     */
    public function isWithinWindow(\DateTimeInterface $at): bool
    {
        if ($this->starts_at !== null && $this->starts_at->greaterThan($at)) {
            return false;
        }

        return $this->ends_at === null || ! $this->ends_at->lessThan($at);
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null
            && $this->redemptions_count >= $this->max_redemptions;
    }
}
