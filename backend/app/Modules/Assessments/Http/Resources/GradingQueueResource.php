<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One paper waiting on a person (FR-027).
 *
 * ⚠️ THE STUDENT'S NAME IS DECIDED HERE, NOT ON THE SCREEN (FR-033). Anonymity
 * applied in the component is anonymity that survives until somebody opens the
 * network tab — and there is no `student` key at all when it is on, rather than
 * a null one, so a client cannot render an empty label where a name used to be
 * and call it a redaction.
 *
 * @mixin Attempt
 */
class GradingQueueResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly bool $anonymous = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $student = $this->student;

        return [
            'uuid' => $this->uuid,
            'exam_title' => $this->exam?->title,
            'submitted_at' => $this->submitted_at,
            // The auto-marked half, labelled as such. Presented bare it reads as
            // the student's result, which is exactly what it is not.
            'auto_score' => (float) $this->score,
            // Counted in the query, never here: a `->count()` inside a Resource
            // is an N+1 by construction, and this list is 500 rows long on the
            // first day of a term.
            'pending_count' => (int) ($this->getAttribute('pending_answers_count') ?? 0),
            ...($this->anonymous || $student === null ? [] : [
                'student' => [
                    'uuid' => $student->uuid,
                    'name' => $student->name,
                ],
            ]),
            'is_anonymous' => $this->anonymous,
        ];
    }
}
