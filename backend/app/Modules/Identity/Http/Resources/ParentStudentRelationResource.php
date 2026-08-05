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
