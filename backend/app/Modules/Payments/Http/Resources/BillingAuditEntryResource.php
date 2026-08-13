<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Support\BillingAuditSubjects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 *
 * One financial decision: what happened, to what, by whom, when — and from where.
 *
 * ⚠️ THE ADDRESS AND THE DEVICE ARE THE HALF THIS PHASE ADDED (FR-024). "Who"
 * alone is an account, and an account is the thing a compromised session
 * borrows; the terminal a decision was taken from is what tells an investigator
 * that four approvals came from one machine at three in the morning.
 *
 * Shaped by hand rather than serialising the model: `subject_id` and `causer_id`
 * are autoincrement keys, which constitution VI keeps out of every payload. The
 * subject is named by its uuid and a slug.
 */
class BillingAuditEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Model|null $subject */
        $subject = $this->subject;

        /** @var Model|null $causer */
        $causer = $this->causer;

        $properties = $this->properties?->toArray() ?? [];

        // Lifted out of the free-form bag and named, because they are the answer
        // to FR-024 rather than incidental context — and because a reader should
        // not have to know which Action happened to spell them which way.
        $ip = $properties['ip_address'] ?? null;
        $agent = $properties['user_agent'] ?? null;

        // The shared trait stamps this on every entry in the product, and it is
        // an autoincrement id like any other.
        unset($properties['workspace_id'], $properties['ip_address'], $properties['user_agent']);

        return [
            'event' => $this->description,
            'subject_type' => BillingAuditSubjects::slugFor($this->subject_type),
            // Null when the row it described has since been deleted. Kept rather
            // than dropped: "this happened and the subject is gone" is precisely
            // what an audit exists to still be able to say.
            'subject_uuid' => $subject?->getAttribute('uuid'),
            // Null when nobody did it — a sweep corrects a payment with no person
            // behind it, and standing in the first super admin would put a name
            // on a decision nobody took.
            'actor_name' => $causer?->getAttribute('name'),
            'ip_address' => is_string($ip) ? $ip : null,
            'user_agent' => is_string($agent) ? $agent : null,
            'properties' => (object) $properties,
            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
