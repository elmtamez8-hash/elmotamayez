<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationWriteBan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationWriteBan> */
class ConversationWriteBanFactory extends Factory
{
    protected $model = ConversationWriteBan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // No `workspace_id`: `BelongsToWorkspace` fills it from the current
        // context — naming one here files the row under a third workspace inside
        // `forWorkspace()`.
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'issued_by' => User::factory(),
            'reason' => 'مقاطعة متكرّرة أثناء الشرح.',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    /** The one that does not end on its own. */
    public function open(): self
    {
        return $this->state(fn (): array => ['expires_at' => null]);
    }
}
