<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Community\GradingSchemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * How one teacher weights the four grade components for a period (FR-049).
 *
 * @property int $course_id 0 means every course in the workspace
 * @property array<string, int> $weights
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 */
class GradingScheme extends BaseModel
{
    /** @use HasFactory<GradingSchemeFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    /** The whole-workspace sentinel — see the migration for why it is not NULL. */
    public const ALL_COURSES = 0;

    /**
     * The four components, in the order every screen and every payload reads
     * them. `participation` is the teacher's own periodic-review axis: it is the
     * only participation signal that exists in the product, and inventing a
     * second one would put two disagreeing numbers on one page.
     */
    public const COMPONENTS = ['exams', 'homework', 'attendance', 'participation'];

    /** Used when a workspace has defined no scheme at all for the period. */
    public const EQUAL_WEIGHTS = [
        'exams' => 25,
        'homework' => 25,
        'attendance' => 25,
        'participation' => 25,
    ];

    protected $fillable = [
        'workspace_id',
        'course_id',
        'period_start',
        'period_end',
        'weights',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            // ⚠️ `date:Y-m-d`, NEVER A BARE `date`. Both columns are part of the
            // unique quadruple, and a bare cast writes through the model's
            // DATETIME format — `2026-08-01 00:00:00` on SQLite, truncated by
            // MySQL. `firstOrNew()` would then miss its own row locally and find
            // it in production. See the longer note on `Review::casts()`.
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'weights' => 'array',
            'course_id' => 'integer',
        ];
    }
}
