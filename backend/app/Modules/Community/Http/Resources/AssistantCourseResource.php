<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as the team screen shows it: on an assistant's card and in the picker
 * that confines them. One shape for both, so the chip and the checkbox cannot
 * disagree about a course.
 *
 * ⚠️ `teacher` READS THE COURSE'S TEACHER WITH `relationLoaded()`, never the bare
 * property. A Resource runs once per row, so an unloaded relation here is an N+1
 * by construction; every caller eager-loads `teacherProfile.user` and `creator`
 * (`first_name,last_name`), and a caller that forgets gets `null` rather than a
 * query per course.
 *
 * ⛔ NOT `creator` ALONE (2026-09-30): on this very screen, a course an
 * ASSISTANT created showed the assistant as its teacher. The creator when they
 * teach here, else the recorded profile's person — `Course::teacherUser()`'s
 * rule.
 * {@see Course::teacherUser()}
 *
 * ⚠️ NO PRICE. The authoring `CourseResource` carries `price_minor`; this one is
 * read by the workspace owner only today, but the shape is the assistant-facing
 * vocabulary, and an assistant never sees money (FR-008).
 *
 * @mixin Course
 */
class AssistantCourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $creator = $this->loadedTeacher();

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'cover_url' => $this->cover_path === null ? null : asset('storage/'.$this->cover_path),
            'status' => $this->status,
            'teacher' => $creator === null ? null : ['name' => $creator->name],
        ];
    }

    /** {@see Course::teacherUser()}, from what the caller eager-loaded and nothing else. */
    private function loadedTeacher(): ?User
    {
        $creator = $this->relationLoaded('creator') ? $this->creator : null;

        // The creator when they teach here (primed by the caller in one query),
        // the recorded profile's person when an assistant created the course.
        if ($this->teacher_profile_id === null || $this->resource->creatorTeaches()) {
            return $creator;
        }

        if (! $this->relationLoaded('teacherProfile')) {
            return null;
        }

        $profile = $this->teacherProfile;

        if ($profile === null) {
            return $creator;
        }

        return $profile->relationLoaded('user') ? $profile->user : null;
    }
}
