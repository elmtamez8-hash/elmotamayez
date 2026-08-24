<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Community\ReportCardSegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One teacher's contribution to one card (FR-041).
 *
 * @property array<string, array{pct: float, weight: float}> $components
 */
class ReportCardSegment extends BaseModel
{
    /** @use HasFactory<ReportCardSegmentFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'report_card_id',
        'workspace_id',
        'teacher_user_id',
        'student_user_id',
        'components',
        'attendance_pct',
        'segment_pct',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'components' => 'array',
            'attendance_pct' => 'float',
            'segment_pct' => 'float',
        ];
    }

    /** @return BelongsTo<ReportCard, $this> */
    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
