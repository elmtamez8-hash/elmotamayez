<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\ReportCardSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportCardSegment
 */
class ReportCardSegmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            // ⚠️ `first_name`/`last_name`, NEVER `name`. `users` has no such
            // column — it is an accessor — so an eager load naming it succeeds,
            // loads the relation, and yields an empty string on every screen.
            'teacher_name' => $this->whenLoaded('teacher', fn (): string => (string) $this->teacher?->name),
            // Only the teacher's own screen loads these two, and it loads them
            // because a list of segments with no student and no period on it is
            // a column of percentages nobody can act on. The student's own card
            // never loads them — it already knows whose it is.
            'student_name' => $this->whenLoaded('student', fn (): string => (string) $this->student?->name),
            'period' => $this->whenLoaded('reportCard', fn (): array => [
                'start' => $this->reportCard?->period_start->toDateString(),
                'end' => $this->reportCard?->period_end->toDateString(),
            ]),
            'components' => $this->components,
            'attendance_pct' => $this->attendance_pct,
            'segment_pct' => $this->segment_pct,
        ];
    }
}
