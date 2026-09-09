<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\PeriodicReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One periodic assessment, as both sides read it.
 *
 * ⚠️ THE AVERAGE IS COMPUTED HERE AND NOWHERE ELSE. Derived in TypeScript as
 * well, the two answers disagree the first time an axis is added — and the screen
 * that shows the wrong one is the student's.
 *
 * ⚠️ AND THERE IS NO STUDENT NAME. The teacher's screen already knows whose row it
 * opened, the student's screen is about themselves, and a name in the payload is
 * one careless list away from FR-035 — a student reading another student's
 * assessment.
 *
 * @mixin PeriodicReview
 */
class PeriodicReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'commitment' => $this->commitment,
            'participation' => $this->participation,
            'homework' => $this->homework,
            'improvement' => $this->improvement,
            'average' => $this->average(),
            'note' => $this->note,
            'is_published' => $this->isPublished(),
            'published_at' => $this->published_at?->toIso8601String(),
            // `whenLoaded`, so a caller that forgets the eager load pays a missing
            // key rather than one query per row — and the query-budget test can
            // see the difference, which it cannot when the value is derived.
            'teacher_name' => $this->whenLoaded('teacher', fn () => $this->teacher?->name),
            /*
             | ⚠️ THE TREND IS PER TEACHER, AND THIS IS THE ONLY KEY THAT CAN
             | GROUP IT. A student's list holds every teacher who assessed them,
             | so «the previous period» read off the array neighbour compares one
             | teacher's month against another's — a fall invented out of two
             | unrelated opinions. The name cannot serve: two teachers may share
             | one, and a rename would silently re-cut the history.
             |
             | ⚠️ AND `uuid` HAD TO JOIN THE CONSTRAINED EAGER LOADS ABOVE IT.
             | `with('teacher:id,first_name,last_name')` selects the columns it
             | names and nothing else, so this key would have been `null` on every
             | row with no error anywhere — the blank-name defect this repository
             | already paid for across six call sites, wearing a different column.
             */
            'teacher_uuid' => $this->whenLoaded('teacher', fn () => $this->teacher?->uuid),
        ];
    }
}
