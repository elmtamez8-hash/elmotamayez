<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Policies\ConversationPolicy;
use App\Modules\Community\Support\CommunitySettings;
use App\Modules\Community\Support\ProspectAllowance;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * What «تواصل مع المدرّس» can do for this reader, with this teacher (2026-09-28).
 *
 * One entry per student the reader may write AS: themselves, or — for a guardian
 * — each child whose relation is active and carries `messages`. Each entry says
 * whether a thread already has messages in it (the button goes straight there),
 * whether a first message may be sent, and why not when it may not.
 *
 * ⚠️ THE PICKER IS DERIVED FROM THE AUTHORISER'S OWN PREDICATE, NEVER ASSEMBLED
 * BESIDE IT (`community.md`). The children are `GuardianDirectory::childrenOf()`
 * under the very permission `ConversationSides` asks, and every entry's verdict
 * is `ConversationPolicy::post()` inspected on the real row or on the same
 * unsaved candidate `StartConversation` would write — so the button can never
 * offer a child the door then refuses, nor hide one it would admit.
 *
 * ⚠️ AND THE COUNT IT SHOWS IS A DISPLAY. The refusal that holds is
 * `PostMessage`'s, under the conversation's row lock.
 */
class ReadContactOptions extends Action
{
    public function __construct(
        private readonly GuardianDirectory $guardians,
        private readonly ProspectAllowance $allowance,
        private readonly StartConversation $threads,
    ) {}

    /**
     * @return array{
     *     options: list<array{student_uuid: string, student_name: string|null, conversation_uuid: string|null, can_start: bool, reason: string|null, is_subscriber: bool, remaining: int|null}>,
     *     note: string|null
     * }
     */
    public function handle(User $actor, string $workspaceUuid): array
    {
        $workspace = Workspace::query()->where('uuid', $workspaceUuid)->first();

        if (! $workspace instanceof Workspace) {
            throw new ModelNotFoundException('لم نجد هذا المدرّس.');
        }

        /*
        | Staff write to students from «راسِل» beside a student's name, never from
        | a public page — and the teacher is not a prospect of their own workspace.
        */
        if ($actor->teachesOnPlatform()) {
            return ['options' => [], 'note' => ConversationPolicy::TEACHING_ACCOUNT];
        }

        if ($actor->platform_role === PlatformRole::Parent) {
            $children = $this->guardians->childrenOf($actor, GuardianPermission::Messages);

            if ($children->isEmpty()) {
                return [
                    'options' => [],
                    'note' => 'لا يوجد ابنٌ مرتبطٌ بحسابك يسمح لك بمراسلة مدرّسيه. اربط حساب ابنك من صفحة «العائلة»، '
                        .'وتأكّد أن صلاحيّة «مراسلة المدرّسين باسم الطالب» مفعّلة.',
                ];
            }

            $options = [];

            foreach ($children as $child) {
                $options[] = $this->optionFor($actor, $workspace, $child, $child->name);
            }

            return ['options' => $options, 'note' => null];
        }

        return ['options' => [$this->optionFor($actor, $workspace, $actor, null)], 'note' => null];
    }

    /**
     * @return array{student_uuid: string, student_name: string|null, conversation_uuid: string|null, can_start: bool, reason: string|null, is_subscriber: bool, remaining: int|null}
     */
    private function optionFor(User $actor, Workspace $workspace, User $student, ?string $label): array
    {
        $existing = $this->threads->find((int) $workspace->getKey(), (int) $student->getKey());

        $conversation = $existing ?? new Conversation([
            'workspace_id' => $workspace->getKey(),
            'kind' => ConversationKind::Private,
            'student_user_id' => $student->getKey(),
        ]);

        $verdict = Gate::forUser($actor)->inspect('post', $conversation);
        $remaining = $this->allowance->remaining($conversation);
        $canStart = $verdict->allowed() && ($remaining === null || $remaining > 0);

        $reason = match (true) {
            ! $verdict->allowed() => $verdict->message(),
            ! $canStart => ProspectAllowance::refusal(CommunitySettings::prospectMessageCap()),
            default => null,
        };

        return [
            'student_uuid' => (string) $student->uuid,
            'student_name' => $label,
            // Only a thread with something in it: an empty one left from before
            // 2026-09-28 is not a conversation to open, and the first message
            // sent from the compose view lands in it anyway.
            'conversation_uuid' => $existing instanceof Conversation && $existing->last_message_id !== null
                ? (string) $existing->uuid
                : null,
            'can_start' => $canStart,
            'reason' => $reason,
            'is_subscriber' => ! $this->allowance->isProspect($conversation),
            'remaining' => $remaining,
        ];
    }
}
