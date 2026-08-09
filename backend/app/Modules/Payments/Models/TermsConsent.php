<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded acceptance of the deferred-payment terms (FR-049).
 *
 * Platform-owned (kind أ): the consent is given TO the platform, which Q-4 made
 * the seller and the holder of the claim. No workspace_id, for the same reason
 * the credit account has none.
 *
 * The signer and the subject are separate columns because they are separate
 * people whenever a guardian signs. Proving the link between them is the Action's
 * job and is not optional: without it any user could sign a legal document in
 * someone else's name, and the response would confirm that the id belongs to a
 * real person.
 *
 * A new version of the terms cannot inherit an old acceptance (FR-049), and this
 * consent never substitutes for the data-processing consent of spec 013, nor the
 * other way round (FR-050).
 */
class TermsConsent extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'student_user_id',
        'document',
        'version',
        'ip_address',
        'user_agent',
        'consented_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
