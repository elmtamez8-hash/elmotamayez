<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as the PUBLIC store shows it — to a guest, or a signed-in reader
 * from any workspace.
 *
 * ⚠️ ITS OWN SHAPE, NOT THE BUYER'S RESOURCE MINUS FIELDS: every key here is on
 * `PublicFieldAllowlist::STORE_ITEM` (+ its nested shapes), and
 * `PublicExposureTest` walks these payloads. No id, no workspace, no raw stock —
 * `is_available` answers the only question a visitor has.
 *
 * Every relation is eager loaded by the controller with the workspace scope
 * dropped; a scoped load answers null for a signed-in reader from elsewhere.
 *
 * @mixin StoreItem
 */
class PublicStoreItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->teacherProfile;

        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'shipping_fee_minor' => $this->shipping_fee_minor,
            'is_available' => $this->stock === null || $this->stock > 0,
            'cover_url' => $this->coverUrl(),
            'teacher' => $profile instanceof TeacherProfile ? [
                'uuid' => $profile->uuid,
                'slug' => $profile->slug,
                'name' => $profile->user?->name,
            ] : null,
            'subject' => $this->subject === null ? null : [
                'slug' => $this->subject->slug,
                'name' => $this->subject->getAttribute('name'),
            ],
            'course' => $this->course === null ? null : [
                'uuid' => $this->course->uuid,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
            ],
        ];
    }
}
