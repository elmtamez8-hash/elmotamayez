<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\TeacherOffboarding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One exit, as the teacher and the officer both see it.
 *
 * ⚠️ NOT ONE FIGURE OF MONEY, AND THAT IS THE CONTRACT'S OWN RULE. The clearance
 * bridge answers two integers in minor units so an officer's Action can decide;
 * what reaches a screen is WHETHER the account is square and WHEN the notice ends.
 * A teacher's outstanding balance shown here is the platform's half of a rate
 * solvable from the other side, which is what `TeacherFieldAllowlist` and
 * `StudentBalanceAllowlist` both exist to stop.
 *
 * ⚠️ AND NO `content_export_path`. It is a path on our disk to an archive holding
 * everything one person authored; the download goes through the ordinary export
 * route, which mints a signature per press and dies five minutes later. The same
 * rule `DataRequestResource` is built on, reached from a second direction.
 *
 * @mixin TeacherOffboarding
 */
class TeacherOffboardingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            /*
            | ⚠️ `dues_cleared`, AND THE NAME IS THE GUARD RATHER THAN A PREFERENCE.
            | `ContextIsolationTest` forbids settlement VOCABULARY in any payload
            | outside that module, and it fired on the obvious spelling of this key
            | — correctly: a screen that talks about settlement is one field away
            | from carrying a number from it. The column keeps its name; what
            | crosses the wire does not.
            */
            'dues_cleared' => $this->duesCleared(),
            'students_notified_at' => $this->students_notified_at?->toIso8601String(),
            'notice_ends_at' => $this->notice_ends_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Whether the archive has been produced at all — the client links to
            // the teacher's own privacy page for it rather than being handed a URL.
            'content_export_ready' => $this->content_export_path !== null,
        ];
    }
}
