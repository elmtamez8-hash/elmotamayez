<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Marketplace\Support\SchoolYearDirectory;
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
            /*
            | Spec 030 — WHICH SIDE IS READING, ANSWERED BY THE SERVER.
            |
            | The screen renders one card from either angle, and deriving the side
            | in TypeScript from `guardian.uuid === me` is the two-spellings defect
            | `cohort_gate` and `BookingEligibility` each paid for.
            |
            | ⚠️ NULL IS A REAL THIRD VALUE, not an oversight: `view()` also admits
            | a TEACHER, through an active enrolment in their own workspace, and a
            | teacher is neither side of the relation. A closed two-value union
            | would have told them "student" about a row they are not party to.
            */
            'viewer_side' => $this->viewerSide($request),
            /*
            | ⚠️ `decidableBy()` DIRECTLY, NEVER `can('accept', …)`. The Gate waves a
            | super admin past every policy method, so through it the one actor the
            | Action refuses would be shown the button. And it is the model method
            | rather than a fourth copy of the predicate — the first draft wrote it
            | out here by hand and dropped `requested_by_user_id !== null`, so an
            | old row rendered an accept button the door answered 403.
            */
            'can_decide' => $this->decideableForReader($request),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'student_name' => $this->student_name,
            'student_age' => $this->student_age,
            /*
            | ⚠️ READ THROUGH THE DIRECTORY, NOT ROW BY ROW (spec 022 · FR-005).
            |
            | `FamilyController` renders a guardian's children as a COLLECTION and
            | a Resource runs once per row, so a per-row lookup here is an N+1 by
            | construction — and the column is TEXT with no relation behind it, so
            | `->with()` is not even available as a fix. `SchoolYearDirectory` is
            | one query for the whole map, memoised for the request.
            |
            | The stage key keeps its name and becomes derived, exactly as on
            | `UserResource`: every existing reader asks for a broad stage.
            */
            'student_grade_level_slug' => app(SchoolYearDirectory::class)
                ->stageFor($this->student_school_year_slug)
                ?? $this->student_grade_level_slug,
            'student_school_year_slug' => $this->student_school_year_slug,
            'student_school_year_name' => app(SchoolYearDirectory::class)
                ->nameFor($this->student_school_year_slug),
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

    /** "guardian" · "student" · null for the teacher reading through the policy. */
    private function viewerSide(Request $request): ?string
    {
        $id = $request->user()?->getKey();

        if ($id === null) {
            return null;
        }

        if ((int) $id === (int) $this->guardian_user_id) {
            return 'guardian';
        }

        return (int) $id === (int) $this->student_user_id ? 'student' : null;
    }

    private function decideableForReader(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $this->resource->decidableBy($user);
    }
}
