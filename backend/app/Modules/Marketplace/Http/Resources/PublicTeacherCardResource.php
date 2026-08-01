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
            'name' => $this->user?->name,
            'headline' => $this->headline,
            'photo_url' => $this->photo_path === null ? null : asset('storage/'.$this->photo_path),
            'subjects' => PublicTaxonomyResource::collection($this->whenLoaded('subjects')),
            'grade_levels' => PublicTaxonomyResource::collection($this->whenLoaded('gradeLevels')),
            'years_experience' => $this->years_experience,
            'teaching_languages' => $this->teaching_languages ?? [],
            'hourly_rate' => (string) $this->hourly_rate,
            'currency' => $this->currency,
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
