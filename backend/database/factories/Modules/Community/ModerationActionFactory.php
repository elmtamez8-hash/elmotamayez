<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\ModerationAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModerationAction> */
class ModerationActionFactory extends Factory
{
    protected $model = ModerationAction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // No `workspace_id`: the trait fills it from the current context. See
        // `AssistantAssignmentFactory` for why naming one here breaks
        // `forWorkspace()` fixtures.
        return [
            'actor_user_id' => User::factory(),
            'subject_type' => ModerationAction::SUBJECT_USER,
            'subject_id' => User::factory(),
            'verdict' => ModerationVerdict::Banned,
            'reason' => 'إساءة',
            'expires_at' => null,
        ];
    }
}
