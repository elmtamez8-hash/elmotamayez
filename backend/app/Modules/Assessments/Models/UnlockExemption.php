<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student let past the condition on one session, on purpose (FR-040).
 *
 * ⚠️ THE REASON IS A COLUMN AND NOT A NOTE SOMEWHERE. An exemption with no
 * stated cause is indistinguishable from a mistake six months later — and the
 * person asking will be a parent who wants to know why their child was treated
 * differently, or the teacher themselves who no longer remembers.
 */
class UnlockExemption extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'class_session_id',
        'student_user_id',
        'reason',
        'granted_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
