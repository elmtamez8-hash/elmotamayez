<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\DataRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One data request, as its owner sees it.
 *
 * ⚠️ NO `export_path`, AND NOT A SIGNED URL EITHER. The same rule
 * `PlaybackGrantResource` is built on: a path in JSON is a link that gets copied,
 * pasted into a ticket and kept — and this one addresses everything the platform
 * knows about one person. The download is a ROUTE the client calls, which answers
 * `302` with a signature minted at that moment and dead five minutes later.
 *
 * ⚠️ AND NO `granted_scope`. It is written from the guardian's permissions at
 * creation, so echoing it back to a student would tell them exactly which
 * categories their guardian may read about them — a fact about the family, from an
 * endpoint that exists to answer a question about the platform.
 *
 * @mixin DataRequest
 */
class DataRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            // `due_at` is NOT NULL in the schema and is written at creation, so
            // there is nothing here to be nullsafe about.
            'due_at' => $this->due_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Whether pressing the button will work, computed from the file and its
            // expiry together — a client that decided this from `status` alone
            // would offer a download for an archive the cleaner removed last night.
            'is_downloadable' => $this->isDownloadable(),
            'export_expires_at' => $this->export_expires_at?->toIso8601String(),
            'refusal_reason' => $this->refusal_reason,

            /*
            | ⚠️ ONLY WHEN THE CALLER EAGER-LOADED IT, WHICH IS THE OFFICER'S QUEUE
            | AND NOTHING ELSE. `whenLoaded` is the guard rather than a permission
            | check: the person's own list does not load the relation, so the key is
            | simply absent there — and an officer reading a queue of requests has to
            | know whose is late, which is the entire use of the screen.
            |
            | The NAME and the uuid, never the email or the phone.
            | `compliance.requests.execute` is the authority to run a request and to
            | see that it ran; it is not a standing entitlement to read contact
            | details for every subject on the platform.
            */
            'subject' => $this->whenLoaded('subject', fn (): array => [
                'uuid' => $this->subject?->uuid,
                'first_name' => $this->subject?->first_name,
                'last_name' => $this->subject?->last_name,
            ]),
        ];
    }
}
