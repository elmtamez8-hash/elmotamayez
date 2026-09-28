<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Contracts\OnlineDirectory;
use App\Modules\Community\Models\Conversation;
use App\Shared\Actions\Action;

/**
 * Which of the reader's private threads have their other end online right now —
 * the green dot beside every name in the conversation list (owner decision,
 * 2026-09-28: «online on the platform», like WhatsApp, privacy trade-off accepted).
 *
 * ⚠️ THE THREADS ARE `ListConversations`' OWN, AND THAT IS THE WHOLE GUARD. Only
 * the other end of a conversation the reader may already open is ever asked
 * about, so the endpoint cannot be used to learn whether an arbitrary person is
 * online: there is no uuid parameter to give it. Two spellings of «whose threads
 * are these» would put one answer in the list and another here.
 *
 * ⚠️ «THE OTHER END» IS ONE PERSON PER SIDE. For the teaching side it is the
 * student. For the student it is the workspace OWNER — the name the row carries
 * (`counterparty_name` is the workspace) — never «any assistant who could
 * answer»: a dot that lit for staff the student never named would say the
 * teacher is here when the teacher is not.
 *
 * Public rooms carry no dot: a class is not a person.
 */
class ListOnlineCounterparts extends Action
{
    public function __construct(
        private readonly ListConversations $conversations,
        private readonly OnlineDirectory $online,
    ) {}

    /** @return list<string> conversation uuids whose counterpart is online */
    public function handle(User $reader): array
    {
        $counterpartOf = [];

        foreach ($this->conversations->handle($reader) as $conversation) {
            if ($conversation->kind->isPublic()) {
                continue;
            }

            $uuid = $this->counterpartUuid($conversation, $reader);

            if ($uuid !== null) {
                $counterpartOf[(string) $conversation->uuid] = $uuid;
            }
        }

        if ($counterpartOf === []) {
            return [];
        }

        $online = array_flip($this->online->onlineAmong(array_values(array_unique($counterpartOf))));

        return array_keys(array_filter($counterpartOf, fn (string $uuid): bool => isset($online[$uuid])));
    }

    private function counterpartUuid(Conversation $conversation, User $reader): ?string
    {
        $person = (int) $conversation->student_user_id === (int) $reader->getKey()
            ? $conversation->workspace?->owner
            : $conversation->student;

        if ($person === null || (int) $person->getKey() === (int) $reader->getKey()) {
            return null;
        }

        return (string) $person->uuid;
    }
}
