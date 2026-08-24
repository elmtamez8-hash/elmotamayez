<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Community\ReportCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One student's cumulative report for one period, across every teacher.
 *
 * ⚠️ NO `BelongsToWorkspace`, DELIBERATELY. This is a platform-owned entity by
 * the constitution's own list — one person, one record of their term. The trait
 * here would create one card per teacher and quietly answer FR-041 by never
 * having anything to separate. The reading guard is therefore explicit and lives
 * in `ReportCardPolicy` and in the controller's own `student_user_id` filter,
 * exactly as `notifications` and `student_credit_accounts` are guarded.
 *
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property CarbonInterface|null $published_at
 * @property-read User|null $student
 */
class ReportCard extends BaseModel implements HasMedia
{
    /** @use HasFactory<ReportCardFactory> */
    use HasFactory, HasUuid, InteractsWithMedia;

    /**
     * ⚠️ `published_at`, `overall_pct` AND `improvement_index` ARE ALL ABSENT.
     * The three are written together inside one conditional UPDATE in
     * `BuildReportCardsJob` — mass-assignable, each becomes a second way to
     * publish from outside the statement that owns the claim, and the totals
     * stop being derived from the segments they claim to summarise.
     */
    protected $fillable = [
        'student_user_id',
        'period_start',
        'period_end',
        'generated_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            // `date:Y-m-d` for the reason spelled out on `GradingScheme` — both
            // columns are part of the unique triple.
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'overall_pct' => 'float',
            'improvement_index' => 'float',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('report_card_pdf')->singleFile();
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /**
     * ⚠️ `withoutGlobalScopes()`, AND WITHOUT IT THE CARD SILENTLY LOSES HALF ITS
     * TEACHERS. `report_card_segments` is a bridge and carries `BelongsToWorkspace`
     * correctly — but this card is platform-owned and its whole purpose is to show
     * every teacher at once, so the scope here filters the document down to
     * whichever workspace the READER happens to resolve to.
     *
     * It survives the obvious test by accident: a student is a member of no
     * workspace, so `WorkspaceContext::id()` is null and the scope adds no
     * condition. The person it breaks for is a guardian who is ALSO a teacher —
     * their `last_workspace_id` resolves, and their child's card comes back
     * carrying their own segment and none of their colleagues'. Found by a
     * two-workspace fixture; a one-workspace one reports it as correct.
     *
     * @return HasMany<ReportCardSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(ReportCardSegment::class)->withoutGlobalScopes();
    }
}
