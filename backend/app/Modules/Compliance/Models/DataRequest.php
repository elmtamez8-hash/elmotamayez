<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to see, export or erase a person's data — PLATFORM (layer أ).
 *
 * ⚠️ NO `BelongsToWorkspace`, AND IT MUST NOT GAIN ONE. One request covers the
 * subject's data at every teacher they study with; a tenant column would split it
 * into one request per workspace, and a partial export is not the right that was
 * implemented. The guard is row ownership plus, for a guardian, an ACTIVE
 * relation carrying `GuardianPermission::DataRights`.
 *
 * @property int $subject_user_id
 * @property int $requested_by_user_id
 * @property DataRequestType $type
 * @property DataRequestStatus $status
 * @property string|null $open_key
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property string|null $export_path
 * @property CarbonImmutable|null $export_expires_at
 * @property list<string>|null $granted_scope
 */
class DataRequest extends BaseModel
{
    use HasUuid;

    /**
     * ⚠️ `open_key` IS DELIBERATELY ABSENT. It is the concurrency lock — written
     * inside the same statement that creates the request and nulled inside the one
     * that closes it. Mass-assignable, it becomes a second way to claim or release
     * that lock from outside the statement that owns it, which is the whole defect
     * it was added to prevent.
     *
     * `status`, `last_attempt_at`, `completed_at` and `executed_by_user_id` are
     * absent for the same reason at one remove: each is written by a conditional
     * UPDATE, and a fillable path around it is a path around the check.
     *
     * @var list<string>
     */
    protected $fillable = [
        'subject_user_id',
        'requested_by_user_id',
        'type',
        'due_at',
        'granted_scope',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => DataRequestType::class,
            'status' => DataRequestStatus::class,
            'due_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'export_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'granted_scope' => 'array',
        ];
    }

    /** The value `open_key` carries while the request is open. */
    public static function openKeyFor(int $subjectUserId, DataRequestType $type): string
    {
        return $subjectUserId.':'.$type->value;
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isDownloadable(): bool
    {
        return $this->export_path !== null
            && $this->export_expires_at !== null
            && $this->export_expires_at->isFuture();
    }
}
