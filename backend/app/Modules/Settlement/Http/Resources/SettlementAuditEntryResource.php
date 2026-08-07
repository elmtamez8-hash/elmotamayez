<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Support\SettlementAuditSubjects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 *
 * One administrative act: what happened, to what, by whom, when.
 *
 * Shaped by hand rather than by serialising the Activity model, and the reason
 * is the model's own columns: `subject_id` and `causer_id` are autoincrement
 * keys, which constitution VI keeps out of every payload. The subject is named
 * by its uuid and a slug instead.
 *
 * `properties` is passed through as stored, which is safe only because the
 * Actions that write it choose the keys — every one of them a settlement number.
 * The single exception is `workspace_id`, which the shared trait adds to every
 * entry in the product and which is an autoincrement id like any other.
 */
class SettlementAuditEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Model|null $subject */
        $subject = $this->subject;

        /** @var Model|null $causer */
        $causer = $this->causer;

        // Null for an entry written with no properties at all — spatie allows
        // it, and this module never does, but the audit reads a table the whole
        // product writes to.
        $properties = $this->properties?->toArray() ?? [];
        unset($properties['workspace_id']);

        return [
            'event' => $this->description,
            'subject_type' => SettlementAuditSubjects::slugFor($this->subject_type),
            // Null when the row it described has since been deleted. Kept as an
            // entry rather than dropped: "this happened and the subject is gone"
            // is precisely the thing an audit exists to still be able to say.
            'subject_uuid' => $subject?->getAttribute('uuid'),
            // Null when nobody did it — the nightly sweep closes periods, and
            // standing in the first super admin would put a name on a decision
            // no person made.
            'actor_name' => $causer?->getAttribute('name'),
            'properties' => (object) $properties,
            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
