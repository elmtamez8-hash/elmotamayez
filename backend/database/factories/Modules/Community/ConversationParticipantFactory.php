<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationParticipant> */
class ConversationParticipantFactory extends Factory
{
    protected $model = ConversationParticipant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'last_read_message_id' => null,
        ];
    }
}
