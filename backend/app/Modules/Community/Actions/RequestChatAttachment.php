<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Somewhere to put a picture or a voice note before it becomes a message
 * (`FR-060`).
 *
 * ⚠️ A SIBLING OF `RequestUploadTicket`, NOT A CALL INTO IT. That Action takes a
 * `Lesson` and enforces three rules that belong to one: the item's type must
 * match the file's kind, a primary asset may not be replaced, and the ceilings
 * are the lesson ceilings. None of the three means anything for a chat, and
 * widening its signature to accept «a lesson or a conversation» would put a
 * `match` on the owner type in the middle of the one Action every lesson upload
 * on the platform goes through.
 *
 * What IS shared is the part worth sharing: `MediaProviderResolver::forKind()`
 * decides who takes the bytes, the asset row records who took them, and the
 * upload ticket is the provider's own. So a chat attachment inherits the
 * provider switch, the retention sweep and the deletion path with no second
 * pipeline behind it.
 *
 * ⚠️ AND THE ASSET IS OWNED BY THE CONVERSATION, NOT BY THE MESSAGE. The message
 * does not exist yet — it is composed after the upload finishes — so an asset
 * keyed to it could only be created by writing the message first, which would put
 * an empty bubble in the thread while the bytes were still moving. The
 * conversation is the durable owner; the message points AT the asset.
 *
 * ⚠️ AND THE GUARD IS `post`, NOT `view`. Uploading is writing: a reader whose
 * enrolment ended keeps the archive and must not be able to add to it, and a
 * banned sender must be refused here exactly as they are refused at the message —
 * otherwise the ban costs them their words and not their pictures.
 */
class RequestChatAttachment extends Action
{
    public function __construct(private readonly MediaProviderResolver $providers) {}

    /** @return array{asset: MediaAsset, ticket: UploadTicket} */
    public function handle(
        User $sender,
        Conversation $conversation,
        string $kind,
        string $originalFilename,
        ?int $declaredSizeBytes = null,
        ?int $declaredDurationSeconds = null,
    ): array {
        if (Gate::forUser($sender)->denies('post', $conversation)) {
            throw new AuthorizationException('لا يمكنك الإرسال في هذه المحادثة.');
        }

        /*
        | ⚠️ TWO CHAT KINDS MAPPED ONTO THE PIPELINE'S OWN, RATHER THAN A NEW
        | `MediaKind`. `document` already accepts `image/png` and `image/jpeg`,
        | and `audio` is what a voice note is — adding an `Image` case would mean
        | a new arm in every exhaustive `match` in `Media` (limits, durations,
        | mime lists, rejection text, provider capabilities) to express something
        | the existing values already carry. The narrower CEILINGS are what the
        | chat actually needs, and those live below.
        */
        $mediaKind = match ($kind) {
            'image' => MediaKind::Document,
            'voice' => MediaKind::Audio,
            default => throw new DomainException('نوع المرفق غير مدعوم.'),
        };

        $this->assertWithinChatLimits($kind, $declaredSizeBytes, $declaredDurationSeconds);

        $provider = $this->providers->forKind($mediaKind);

        $asset = new MediaAsset([
            'workspace_id' => $conversation->workspace_id,
            'owner_type' => Conversation::class,
            'owner_id' => $conversation->getKey(),
            // Stamped from whoever actually took it, never from the config —
            // `MediaProviderResolver::for()` reads this column back later, and a
            // row recording the CONFIGURED provider is a lie the day a kind is
            // routed elsewhere.
            'provider' => $provider->identifier(),
            'kind' => $mediaKind,
            // Never `Primary`: that role means «the lesson's own file» and is what
            // `PublishReadiness` counts. A chat attachment is an attachment.
            'role' => MediaRole::Attachment,
            // A chat picture is displayed, not handed over as a file. Stated
            // explicitly because a model built with `new` carries no column
            // default and the cast would otherwise return null.
            'is_downloadable' => false,
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $originalFilename,
            'size_bytes' => $declaredSizeBytes,
            'duration_seconds' => $declaredDurationSeconds,
        ]);

        $asset->save();

        return [
            'asset' => $asset,
            'ticket' => $provider->createUploadTicket($asset),
        ];
    }

    /**
     * The declared numbers, checked before a byte moves.
     *
     * A claim the client can lie about — the real check happens on completion
     * against the file itself — but refusing an oversized upload up front saves a
     * student on a phone watching a transfer that was always going to fail.
     */
    private function assertWithinChatLimits(string $kind, ?int $sizeBytes, ?int $durationSeconds): void
    {
        $maxBytes = MediaLimits::maxChatAttachmentBytes();

        if ($sizeBytes !== null && $sizeBytes > $maxBytes) {
            throw new DomainException(sprintf(
                'حجم المرفق يتجاوز الحد المسموح في المحادثات (%s كحدّ أقصى).',
                MediaLimits::humanBytes($maxBytes),
            ));
        }

        if ($kind !== 'voice' || $durationSeconds === null) {
            return;
        }

        $maxSeconds = MediaLimits::maxVoiceNoteSeconds();

        if ($durationSeconds > $maxSeconds) {
            throw new DomainException(sprintf(
                'مدة الرسالة الصوتية تتجاوز الحد المسموح (%d دقائق كحدّ أقصى).',
                intdiv($maxSeconds, 60),
            ));
        }
    }
}
