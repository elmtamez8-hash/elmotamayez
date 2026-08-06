<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Settlement\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only. The sum of the entries IS the balance (FR-022 · NFR-009).
 *
 * The refusal to update or delete is enforced here as well as in the Action,
 * because the Action is only the entry point the API uses: a seeder, a Filament
 * resource or a future console command all reach the model directly, and a
 * ledger with one honest path and three quiet ones is not a ledger.
 *
 * @property LedgerEntryType $type
 * @property int $amount_minor
 */
class LedgerEntry extends BaseModel
{
    /** @use HasFactory<LedgerEntryFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'type',
        'amount_minor',
        'currency',
        'teaching_unit_id',
        'settlement_period_id',
        'payout_id',
        'reason',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('قيد الدفتر لا يُعدَّل. التصحيح بقيد عكسي جديد.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('قيد الدفتر لا يُحذف. التصحيح بقيد عكسي جديد.');
        });
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
