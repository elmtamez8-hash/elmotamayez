<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Support\SchedulableTeachers;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⚠️ UUIDS ON THE WIRE — see {@see StoreClassSessionRequest} for why the raw ids
 * had to go, and what they cost.
 */
class GenerateSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // ⚠️ OPTIONAL, like its sibling on the single-session request: the
            // ordinary caller is the teacher themselves and names nobody.
            'teacher_profile_uuid' => ['sometimes', 'uuid', WorkspaceRules::exists('teacher_profiles', 'uuid')],
            // Required since Q-7 for the same reason as on a single session: the
            // price is a property of the course.
            'course_uuid' => ['required', 'uuid', WorkspaceRules::exists('courses', 'uuid')],
            'from' => ['required', 'date'],
            // Bounded so one request cannot generate a decade of sessions and
            // spend the rest of the afternoon doing it.
            'to' => ['required', 'date', 'after:from', 'before:'.now()->addYear()->toDateString()],
            'slot_uuids' => ['sometimes', 'array'],
            'slot_uuids.*' => ['uuid'],
            'seats_total' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'type' => ['sometimes', Rule::enum(ClassSessionType::class)],
            'title' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * The teacher named by the payload.
     *
     * The rule above already proved the row exists and is in this workspace, so
     * this cannot miss — `firstOrFail` is the honest spelling of that.
     */
    public function teacherProfile(): TeacherProfile
    {
        $named = $this->validated('teacher_profile_uuid');

        if ($named !== null) {
            return TeacherProfile::query()->where('uuid', $named)->firstOrFail();
        }

        $user = $this->user();
        $own = $user instanceof User ? app(SchedulableTeachers::class)->ownProfile($user) : null;

        if (! $own instanceof TeacherProfile) {
            throw ValidationException::withMessages([
                'teacher_profile_uuid' => 'لا يوجد ملفّ مدرّس لحسابك.',
            ]);
        }

        return $own;
    }

    public function course(): Course
    {
        return Course::query()
            ->where('uuid', $this->validated('course_uuid'))
            ->firstOrFail();
    }
}
