<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Support\DiscountResolver;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\MinorUnits;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int $value a whole percent, or MINOR units for a fixed amount — see `value_kind`
 * @property string|null $amount major units («49.99») over `value` for a FIXED coupon; null for a percent one
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
        /*
        | The virtual major-unit spelling of `value` for a fixed coupon — see
        | `amount()`. Fillable because the panel's create and edit pages are
        | Filament's defaults (`new Coupon($data)` / `update($data)`), and a key
        | missing here is DISCARDED IN SILENCE: the coupon would save with no value.
        */
        'amount',
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
     * A fixed coupon's amount in major units — what the admin types and reads.
     *
     * ⛔ `value` is ONE column with TWO meanings (a whole percent, or a minor
     * amount), so this reads null for a percent coupon rather than dressing «50»
     * up as «0.50». Writing it writes `value` in minor units and nothing else:
     * the kind is its own field, and the form sends both.
     *
     * @return Attribute<string|null, mixed>
     */
    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => ($attributes['value_kind'] ?? null) === CouponValueKind::FixedMinor->value
                && is_numeric($attributes['value'] ?? null)
                    ? MinorUnits::toMajor((int) $attributes['value'])
                    : null,
            set: fn (mixed $value): array => ['value' => MinorUnits::fromMajorOrFail($value)],
        );
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

    /**
     * The teacher a scoped code is narrowed to — for display only.
     *
     * A SCOPE, not an owner (see the class docblock): no global scope rides on
     * this read, because `Workspace` carries none and a coupon belongs to none.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
