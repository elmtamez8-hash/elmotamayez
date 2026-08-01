<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Http\Request;

/**
 * Full public profile. Extends the card so the two can never drift apart.
 *
 * @mixin TeacherProfile
 */
class PublicTeacherDetailResource extends PublicTeacherCardResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'bio' => $this->bio,
            'qualifications' => $this->qualifications ?? [],

            'stats' => [
                'students_taught' => $this->students_taught_count,
                'completed_sessions' => $this->completed_sessions_count,
                'response_rate' => $this->response_rate,
                'attendance_rate' => $this->attendance_rate,
            ],

            // The five factors behind the number. Showing the score alone asks the
            // visitor to trust an opaque figure, which is the opposite of the point.
            'trust_score_factors' => $this->trust_score_factors,

            // Filled in by the controller, which has the courses Action; reviews
            // land with US5. Empty is honest: the profile renders "no reviews yet"
            // rather than inventing data.
            'courses' => [],
            'reviews' => [
                'average' => $this->average_rating === null ? null : (float) $this->average_rating,
                'total' => $this->reviews_count,
                // Cast to object: PHP turns numeric string keys into integers, and
                // json_encode then emits a JSON array instead of the keyed object
                // the contract (and the client type) expects.
                'distribution' => (object) ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
                'items' => [],
            ],

            'availability' => $this->whenLoaded(
                'availabilitySlots',
                fn () => $this->availabilitySlots
                    ->sortBy(['day_of_week', 'start_time'])
                    ->map(fn (AvailabilitySlot $slot) => [
                        'day_of_week' => $slot->day_of_week,
                        // UTC: the client renders these in the visitor's timezone.
                        'start_time' => substr($slot->start_time, 0, 5),
                        'end_time' => substr($slot->end_time, 0, 5),
                    ])
                    ->values(),
                [],
            ),

            'faqs' => [],
        ];
    }
}
