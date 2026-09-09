<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Shared\Support\GuardianPermission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParentStudentRelation>
 */
class ParentStudentRelationFactory extends Factory
{
    protected $model = ParentStudentRelation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'guardian_user_id' => User::factory(),
            'student_user_id' => User::factory(),
            'student_name' => $this->faker->firstName(),
            'student_age' => 15,
            'student_grade_level_slug' => null,
            'relation_type' => RelationType::Guardian->value,
            'permissions' => GuardianPermission::values(),
            'status' => RelationStatus::Active->value,
            'revoked_at' => null,
        ];
    }

    /**
     * ⚠️ THE REQUESTER DEFAULTS TO THE GUARDIAN, NEVER TO NULL (spec 030).
     *
     * `decidableBy()` reads a NULL requester as "nobody may settle this" — the
     * correct answer for a row created before the column existed, and a silent
     * disaster in a fixture: every acceptance test would measure the 403 branch
     * and pass, proving the opposite of what it claims. Set here rather than in
     * `definition()` because `guardian_user_id` is still a Factory instance until
     * the attributes are expanded.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ParentStudentRelation $relation): void {
            $relation->requested_by_user_id ??= $relation->guardian_user_id;
        });
    }

    /** A link waiting on the party who did not ask for it. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RelationStatus::Pending->value,
        ]);
    }

    public function parent(): static
    {
        return $this->state(fn (array $attributes) => [
            'relation_type' => RelationType::Parent->value,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RelationStatus::Revoked->value,
            'revoked_at' => now(),
        ]);
    }

    /** @param  list<GuardianPermission>  $permissions */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'permissions' => array_map(fn (GuardianPermission $p) => $p->value, $permissions),
        ]);
    }
}
