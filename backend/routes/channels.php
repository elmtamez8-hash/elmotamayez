<?php

declare(strict_types=1);

/*
| Spec 010 — the broadcast channel authorisation map.
|
| ⚠️ THIS FILE IS THE ONLY PLACE A CHANNEL NAME IS ENUMERATED, and that is the
| whole of `NFR-007`. Loaded through `channels:` in `bootstrap/app.php`, which is
| also what creates `/broadcasting/auth` — without that line there is no
| authorisation endpoint at all and every subscription fails with nothing saying
| why.
|
| ⚠️ AND EVERY CALLBACK HERE CALLS THE ACTION'S OWN GUARD, never a second
| condition written beside it. Two spellings of "may this person read this
| conversation" put one answer on the screen and another at the door — the defect
| this repository has already recorded for `BookingEligibility` and for
| `ListLeaderboardScopes`.
|
| ⚠️ AUTHORISATION HAPPENS ONCE, AT SUBSCRIBE, AND THE PROTOCOL HAS NO REVOCATION.
| An assistant whose permission is withdrawn while still connected keeps receiving
| events on a channel they were admitted to. What makes that tolerable is that the
| payload is an IDENTIFIER and nothing else: the fetch that follows goes through
| the authenticated route and is refused there. That is a second reason for the
| id-only rule, and it is written down in both places on purpose.
|
| Laravel's default `App.Models.User.{id}` channel was removed rather than left:
| this product's notifications are rows in our own table read over HTTP, the
| Filament panel does not enable broadcast notifications, and a channel nobody
| publishes to is a subscription surface with no owner.
*/

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
| The open thread.
|
| ⚠️ THE NAME HERE CARRIES NO `private-` PREFIX. The client sends
| `private-conversation.{uuid}`; Laravel strips the prefix before matching, so a
| definition written with it matches nothing and every subscription is refused
| with nothing anywhere naming why.
|
| ⚠️ AND THE CONVERSATION IS RESOLVED WITHOUT THE WORKSPACE SCOPE. Channel
| authorisation runs in a request like any other, and for a student
| `WorkspaceContext::id()` is null — the scope adds no condition, so relying on it
| here would be relying on nothing. The uuid is looked up explicitly and the
| POLICY decides, which is the same method `ReadMessages` asks.
*/
Broadcast::channel('conversation.{uuid}', function (User $user, string $uuid): bool {
    $conversation = Conversation::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();

    return $conversation instanceof Conversation
        && Gate::forUser($user)->allows('view', $conversation);
});

/*
| One person's own channel — how a list screen learns that a thread it is not
| looking at has moved.
|
| The guard is identity, not permission: the only thing published here is
| «something of yours changed», addressed to you by your own uuid.
*/
Broadcast::channel('user.{uuid}', fn (User $user, string $uuid): bool => (string) $user->uuid === $uuid);

/*
| Who is in this thread right now, and who is typing (`FR-058` · `FR-059`).
|
| ⚠️ A PRESENCE CHANNEL WITH ITS OWN NAME, NOT THE PRIVATE ONE. Laravel strips
| `private-` AND `presence-` before matching, so reusing `conversation.{uuid}`
| would give one definition two meanings — and the private subscription would
| start receiving the member array simply because a truthy return authorises it.
| A separate name keeps «may this person read the thread» and «who is watching it»
| two questions with two answers.
|
| ⚠️ AND THE CHANNEL IS WHAT MAKES «يكتب الآن» POSSIBLE AT ALL. Reverb's
| `accept_client_events_from` is `members`, so a whisper is refused on a private
| channel and accepted on a presence one — the typing indicator is not a design
| preference here, it is the only shape the protocol allows without a route, a
| table and a write per keystroke.
|
| ⚠️ AND NOTHING IS STORED. `FR-058` forbids a «last seen» column outright: what
| is not written cannot be exported, retained, or turned into a record of when a
| child was awake. The array below is assembled per connection and dies with it.
|
| The guard is the SAME method the door uses — `ConversationPolicy::view()` —
| never a second condition written beside it.
*/
Broadcast::channel('chat-presence.{uuid}', function (User $user, string $uuid): array|bool {
    $conversation = Conversation::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();

    if (! $conversation instanceof Conversation || ! Gate::forUser($user)->allows('view', $conversation)) {
        return false;
    }

    // A name and a uuid. No email, no phone, no role: this payload is handed to
    // everyone else in the room, and a presence member list is the easiest place
    // in a chat product to leak a contact detail without noticing.
    return ['uuid' => (string) $user->uuid, 'name' => $user->name];
});
