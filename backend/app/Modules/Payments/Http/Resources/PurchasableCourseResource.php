<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Courses\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One option in the credit picker — four fields and no fifth.
 *
 * ⛔ DELIBERATELY NOT `CourseResource`. That one carries `status`, `visibility`,
 * `price_minor`, `price_before_discount_minor` and `promo_video_status` — the
 * course's whole commercial state, on a payload whose reader is a PARENT choosing
 * which of their child's courses to top up. `price_minor` beside a credit total is
 * two prices for one course from two pricing models, and `promo_video_status` is a
 * moderation decision about the teacher's own marketing.
 *
 * The narrowness is enforced rather than intended: `PurchasableCourseAllowlistTest`
 * reds the build over a key added here, the same
 * shape as `StudentBalanceAllowlist` — a resource with no allowlist grows a field
 * at a time and nobody notices the one that mattered.
 *
 * @property Course $resource
 */
class PurchasableCourseResource extends JsonResource
{
    /**
     * The complete set of keys this resource may ever send.
     *
     * @var list<string>
     */
    public const FIELDS = ['uuid', 'title', 'teacher_name', 'cover_url'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'title' => $this->resource->title,
            /*
            | The ACADEMY's name, which is what a student calls their teacher here
            | — the same field `CreditBalanceResource` sends under this name, so
            | the picker and the balance card cannot disagree about who a course
            | belongs to.
            */
            'teacher_name' => $this->resource->workspace?->name,
            'cover_url' => $this->resource->cover_path === null
                ? null
                : asset('storage/'.$this->resource->cover_path),
        ];
    }
}
