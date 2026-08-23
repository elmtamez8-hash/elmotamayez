<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\AssistantAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One member of the teacher's team.
 *
 * ⚠️ `uuid` AND NEVER `id`, on the assignment, on the assistant and on every
 * course. `HasUuid` makes the uuid the route key, so an autoincrement id in a
 * payload is a second address for the same row that no route accepts and every
 * client eventually sends.
 *
 * ⚠️ AND THERE IS NO `AssistantPayloadAllowlist`, deliberately. The allowlists
 * elsewhere in this product exist because a payload carries a number that must
 * not travel; this one carries a name, a date and a course list. The financial
 * question here is not «what is in the body» but «is the route refused at all»,
 * and that is measured over HTTP in `AssistantFinancialWallTest`. An allowlist
 * would be a guard pointing at the wrong door.
 *
 * @mixin AssistantAssignment
 */
class AssistantAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $assistant = $this->assistant;
        $workspace = $this->relationLoaded('workspace') ? $this->workspace : null;

        return [
            'uuid' => $this->uuid,
            'assistant' => $assistant === null ? null : [
                'uuid' => $assistant->uuid,
                'name' => $assistant->name,
            ],
            'workspace' => $workspace === null ? null : [
                'uuid' => $workspace->uuid,
                'name' => $workspace->name,
            ],
            // ⚠️ AN EMPTY LIST IS «EVERY COURSE», NOT «NO COURSES». The client is
            // told which it is by this flag rather than being left to infer it
            // from a length — the inference is the one that gets written
            // backwards, and backwards means an assistant shown as locked out of
            // everything on the day they were invited.
            'is_confined' => $this->scopes->isNotEmpty(),
            'courses' => $this->scopes
                ->map(fn ($scope): ?array => $scope->course === null ? null : [
                    'uuid' => $scope->course->uuid,
                    'title' => $scope->course->title,
                ])
                ->filter()
                ->values()
                ->all(),
            'revoked_at' => $this->revoked_at,
        ];
    }
}
