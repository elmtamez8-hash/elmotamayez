<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public teacher card.
 *
 * Every key is written out by hand and matched against
 * PublicFieldAllowlist::TEACHER_CARD by PublicExposureTest. Never reach for
 * parent::toArray() or $this->resource->toArray() here: that publishes whatever
 * column someone adds to the table next, to every anonymous visitor.
 *
 * @mixin TeacherProfile
 */
class PublicTeacherCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->user?->name,
            'headline' => $this->headline,
            'photo_url' => $this->photo_path === null ? null : asset('storage/'.$this->photo_path),
            'subjects' => PublicTaxonomyResource::collection($this->whenLoaded('subjects')),
            'grade_levels' => PublicTaxonomyResource::collection($this->whenLoaded('gradeLevels')),
            'years_experience' => $this->years_experience,
            'teaching_languages' => $this->teaching_languages ?? [],
            /*
            | ⚠️ NO RATE, AND NO CURRENCY BESIDE IT (spec 006, FR-021و).
            |
            | 001 published this. 006 makes the platform the seller: the student
            | pays a cost-plus total and the teacher is paid an approved
            | settlement rate, and FR-021ب forbids the two meeting on any screen.
            | Published side by side they are the whole equation, and the
            | platform's margin is a subtraction away.
            |
            | The column stays — it is the teacher's own input on their
            | application and the seed of a rate-change request in 014.
            | `hourly_rate` is in PublicFieldAllowlist::FORBIDDEN so this cannot
            | come back by accident, at any nesting depth.
            */
            'average_rating' => $this->average_rating === null ? null : (float) $this->average_rating,
            'reviews_count' => $this->reviews_count,
            // Null with band "building" — never 0 with band "low", which would read
            // as a bad teacher rather than a new one (FR-024).
            'trust_score' => $this->trust_score,
            'trust_score_band' => $this->trustScoreBand(),
            'is_verified' => $this->is_verified,
            'available_now' => $this->isAvailableNow(),
        ];
    }
}
