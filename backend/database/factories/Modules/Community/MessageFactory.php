<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_user_id' => User::factory(),
            'body' => 'رسالة تجريبيّة.',
        ];
    }

    /**
     * A message the sender hid (FR-015).
     *
     * ⚠️ `hidden_at` IS NOT FILLABLE, so it goes through `afterMaking` — a
     * non-fillable key handed to `create()` is DISCARDED IN SILENCE, and the
     * fixture would be a visible message wearing the name of a hidden one.
     */
    public function hidden(): self
    {
        return $this->afterMaking(function (Message $message): void {
            $message->forceFill(['hidden_at' => now()]);
        });
    }
}
