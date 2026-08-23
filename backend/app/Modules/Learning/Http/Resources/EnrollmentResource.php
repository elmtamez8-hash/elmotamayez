<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Enrollment */
class EnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'course_uuid' => $this->course->uuid,
            'course_title' => $this->course->title,
            /*
            | Spec 010 — which teacher's side this course belongs to.
            |
            | ⚠️ IT IS HERE BECAUSE A PRIVATE CONVERSATION HAS NO OTHER DOOR. The
            | chat is one per student per workspace and it is opened by naming
            | that workspace; a student's enrolments are the only list they hold
            | of the teachers they may write to, and the two questions have the
            | same answer for the same reason. Without it «راسل المدرّس» would
            | need a second directory of teachers to pick from — and that second
            | list would answer «whom may I write to» in a different voice from
            | the one the endpoint uses.
            |
            | A uuid and a name, and nothing else about the workspace: this
            | payload reaches a student.
            */
            'workspace_uuid' => $this->workspace?->uuid,
            'teacher_name' => $this->workspace?->name,
            'status' => $this->status,
            'source' => $this->source,
            'progress_pct' => $this->progress_pct,
            'enrolled_at' => $this->enrolled_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
