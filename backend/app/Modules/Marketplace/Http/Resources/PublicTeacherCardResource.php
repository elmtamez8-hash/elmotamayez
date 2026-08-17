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
            /*
            | ⚠️ `->resolve()` ON THE NESTED COLLECTION, AND `whenLoaded` WITH A
            | CALLBACK — because this card is CACHED and `resolve()` does not
            | recurse.
            |
            | `JsonResource::resolve()` runs `toArray()` and stops. A nested
            | `AnonymousResourceCollection` left in the returned array is still an
            | OBJECT, and it renders correctly only because `json_encode` walks
            | `JsonSerializable` on the way out. Put that array in a cache instead
            | — which is exactly what `PublicMarketplaceController::teachers()`
            | and `GetMarketplaceHome` do — and the object is serialized whole. It
            | comes back from the store as `__PHP_Incomplete_Class`, and the cache
            | HIT publishes this to anonymous visitors:
            |
            |   "subjects": {"__PHP_Incomplete_Class_Name":
            |       "Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection",
            |       "collects": "App\\Modules\\Marketplace\\...", ...}
            |
            | Internal class paths on a public endpoint, and every subject chip
            | gone from every card — for the whole TTL, on a MISS/HIT boundary no
            | reader would think to look at. It is invisible in every test that
            | asserts against a fresh response, and `discovery.spec.ts` only caught
            | it by rendering the same url twice and getting two different pages.
            |
            | The callback form of `whenLoaded` matters for the same reason: the
            | bare form returns a `MissingValue` that `::collection()` wraps into a
            | one-item collection, so an unloaded relation would resolve a resource
            | over MissingValue instead of omitting the key.
            */
            'subjects' => $this->whenLoaded(
                'subjects',
                fn () => PublicTaxonomyResource::collection($this->subjects)->resolve(),
            ),
            'grade_levels' => $this->whenLoaded(
                'gradeLevels',
                fn () => PublicTaxonomyResource::collection($this->gradeLevels)->resolve(),
            ),
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
