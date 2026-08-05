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
