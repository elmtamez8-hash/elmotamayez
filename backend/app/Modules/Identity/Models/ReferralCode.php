<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person, one code, for life (spec 011 · FR-018).
 *
 * ⚠️ NO `BelongsToWorkspace`. A code belongs to a HUMAN, not to a classroom;
 * the trait would duplicate one person per teacher, which is the mirror-image
 * bug `PlatformOwnershipTest` exists to catch. It follows that no global scope
 * guards this table and every read names its owner explicitly.
 *
 * @property int $user_id
 * @property string $code
 */
class ReferralCode extends BaseModel
{
    use HasUuid;

    protected $fillable = ['user_id', 'code'];

    /**
     * A code somebody has to type off a phone screen.
     *
     * ⚠️ NO `I`, `O`, `0` OR `1`. A code is read aloud and retyped by the person
     * being invited, and those four are the pairs every handwritten code loses —
     * a referral that fails on a misread character is a referral that looks like
     * the feature is broken. Upper case for the same reason the coupon code is,
     * and compared after `strtoupper` so what the friend types is what they see.
     */
    public static function generate(): string
    {
        return substr(str_replace(['I', 'O', '0', '1'], '', Str::upper(Str::random(24))), 0, 8);
    }

    public static function normalise(string $code): string
    {
        return Str::upper(trim($code));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
