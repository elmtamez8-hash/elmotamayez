<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // No `workspace_id`: `BelongsToWorkspace` fills it from the current
        // context, and naming one here would file the row under a third
        // workspace inside `forWorkspace()`. See `AssistantAssignmentFactory`.
        return [
            'kind' => ConversationKind::Private,
            'student_user_id' => User::factory(),
        ];
    }
}
