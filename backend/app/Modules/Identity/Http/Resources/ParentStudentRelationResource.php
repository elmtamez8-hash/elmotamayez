<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\ParentStudentRelation;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ParentStudentRelation
 */
class ParentStudentRelationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'relation_type' => $this->relation_type,
            'relation_type_label' => $this->relationType()->label(),
            'status' => $this->status,
            'status_label' => $this->status()->label(),
            'student_name' => $this->student_name,
            'student_age' => $this->student_age,
            'student_grade_level_slug' => $this->student_grade_level_slug,
            // Whether the student has an account, without saying whose it is.
            'student_has_account' => $this->student_user_id !== null,
            /*
            | ⚠️ THE UUID ONLY FOR THE GUARDIAN ON THIS VERY ROW, which is why the
            | line above stays as it is rather than being replaced.
            |
            | A teacher may also read this resource — an active enrolment in their
            | own workspace is the gate — and a student may read their own
            | guardians. Neither has any business being handed an identifier for
            | somebody else's child, and `student_has_account` deliberately says
            | «there is an account» without saying whose.
            |
            | The guardian needs it because every child-scoped read on the platform
            | takes `?student={uuid}` (010's periodic assessments, 006's balances),
            | and without it a guardian has no route to their own child's screens
            | at all — the exact gap `GuardianDirectory::childrenOf()` was added to
            | close on the server, left open on the client.
            */
            'student_uuid' => $this->when(
                $this->student_user_id !== null
                    && $request->user()?->getKey() === $this->guardian_user_id,
                fn () => $this->student?->uuid,
            ),
            'guardian' => $this->whenLoaded('guardian', fn () => [
                'uuid' => $this->guardian->uuid,
                'name' => $this->guardian->name,
            ]),
            'permissions' => array_map(
                fn (string $value): array => [
                    'key' => $value,
                    'label' => GuardianPermission::from($value)->label(),
                ],
                $this->permissions,
            ),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
