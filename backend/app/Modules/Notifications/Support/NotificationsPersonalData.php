<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;

/**
 * Notifications's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class NotificationsPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'notifications';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['notification_record'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        $userId = $subject->user->getKey();

        /*
        | ⚠️ THE FEED IS FILTERED BY THE TYPE'S OWN PERMISSION, not by a second list
        | written here. `NotificationType::requiredGuardianPermission()` already
        | answers "which guardian may hear about this", and a message body carries
        | the very thing the gate exists for — a mark, an amount, a warning. A
        | guardian granted presence alone would otherwise be handed every payment
        | reminder their child ever received, in full sentences, having been refused
        | the payment records two files earlier in the same archive.
        |
        | A type with no required permission is one that is nobody's secret; it
        | travels.
        */
        /*
        | ⚠️ THE ALLOWED TYPES ARE DERIVED FROM THE ENUM AND THEN ASKED IN SQL, which
        | is neither a filter in PHP over every row nor a second copy of the rule
        | written in strings. `requiredGuardianPermission()` stays the only place
        | that decides, and a type added tomorrow is covered without touching this
        | file — the list is built from `cases()`, not from a literal.
        */
        $allowedTypes = array_values(array_map(
            fn (NotificationType $type): string => $type->value,
            array_filter(NotificationType::cases(), function (NotificationType $type) use ($subject): bool {
                $required = $type->requiredGuardianPermission();

                return $required === null || $subject->mayReceive($required);
            }),
        ));

        yield from ExportWalk::keyed(
            'notification_record',
            Notification::query()
                ->where('recipient_user_id', $userId)
                ->whereIn('type', $allowedTypes),
            fn (Notification $notification): array => [
                'uuid' => $notification->uuid,
                // A plain string column: `Notification` casts `payload` and `read_at` and
                // nothing else, so there is no enum here to unwrap.
                'type' => $notification->type,
                'title' => $notification->title_ar,
                'body' => $notification->body_ar,
                'read_at' => ExportWalk::at($notification->read_at),
                'created_at' => ExportWalk::at($notification->created_at),
            ],
        );

        yield from ExportWalk::keyed(
            'notification_record',
            NotificationPreference::query()->where('user_id', $userId),
            fn (NotificationPreference $preference): array => [
                'uuid' => $preference->uuid,
                'type' => $preference->type,
                'channels' => $preference->channels,
                'digest_window_minutes' => $preference->digest_window_minutes,
            ],
        );

        /*
        | ⚠️ THE VERIFIED CONTACT DETAIL, AND NEVER `code_hash`. The number is the
        | person's own and is the one the platform actually messages — 020 reads it
        | from here rather than from `users.phone`, so a person checking what we
        | hold about them must be able to see the value that is really in use. The
        | hash beside it is a credential;
        | {@see \App\Modules\Compliance\Support\ExportFieldAllowlist} fails the build
        | over any key containing it.
        */
        yield from ExportWalk::keyed(
            'notification_record',
            ContactVerification::query()->where('user_id', $userId),
            fn (ContactVerification $verification): array => [
                'uuid' => $verification->uuid,
                'channel' => $verification->channel,
                'contact_value' => $verification->contact_value,
                'verified_at' => ExportWalk::at($verification->verified_at),
                'created_at' => ExportWalk::at($verification->created_at),
            ],
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        // TODO(013-US4): erase or anonymise this module's rows for the subject.
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
