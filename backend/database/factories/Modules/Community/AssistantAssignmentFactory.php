<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssistantAssignment> */
class AssistantAssignmentFactory extends Factory
{
    protected $model = AssistantAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        /*
        | ⚠️ NO `workspace_id` HERE, DELIBERATELY. `BelongsToWorkspace` fills it
        | from the current context on create, and a factory that names its own
        | workspace overrides that — so `forWorkspace($a, fn () => …factory()
        | ->create())` would file the row under a THIRD workspace nobody asked
        | for, and the isolation test would count zero in both. The shipped
        | tenant factories (`AvailabilitySlot`, `TeacherProfile`) omit it for the
        | same reason.
        |
        | Outside any context the column is null and the insert fails loudly,
        | which is the right answer: a tenant row with no tenant is not a fixture.
        */
        return [
            'assistant_user_id' => User::factory(),
            'invited_by_user_id' => User::factory(),
            'revoked_at' => null,
        ];
    }

    /**
     * A removed assistant.
     *
     * ⚠️ `revoked_at` IS NOT FILLABLE, so this state must go through `afterMaking`
     * rather than the definition array — a non-fillable key handed to `create()`
     * is DISCARDED IN SILENCE, and the fixture would be a live assistant wearing
     * the name of a revoked one. Spec 013 shipped three columns that way.
     */
    public function revoked(): self
    {
        return $this->afterMaking(function (AssistantAssignment $assignment): void {
            $assignment->forceFill(['revoked_at' => now()]);
        });
    }
}
