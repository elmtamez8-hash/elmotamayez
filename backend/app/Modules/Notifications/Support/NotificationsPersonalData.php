<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushSubscription;
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
        return ['notification_record', 'push_subscription'];
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
                'title' => $notification->title,
                'body' => $notification->body,
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

        /*
        | Spec 012 · US2 — the devices that may be woken.
        |
        | ⚠️ THE ENDPOINT IS MASKED, AND THAT IS NOT OVER-CaUTION. A push endpoint
        | is a bearer capability: whoever holds it, plus the two keys beside it,
        | can deliver a notification to that device. The archive is a file the
        | subject downloads and may forward, so the whole value travelling would
        | put a working handle to their phone in it — and `p256dh`/`auth` are
        | credentials in the family `ExportFieldAllowlist` fails the build over.
        | What the person actually asked is «which devices do you hold», and the
        | user agent and the dates answer that.
        */
        yield from ExportWalk::keyed(
            'push_subscription',
            PushSubscription::query()->where('user_id', $userId),
            fn (PushSubscription $subscription): array => [
                'uuid' => $subscription->uuid,
                'device' => $subscription->user_agent,
                'endpoint_host' => parse_url($subscription->endpoint, PHP_URL_HOST),
                'last_used_at' => ExportWalk::at($subscription->last_used_at),
                'created_at' => ExportWalk::at($subscription->created_at),
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
        if ($mode !== ErasureMode::Delete) {
            return 0;
        }

        $userId = $subject->user->getKey();

        /*
        | ⚠️ THE DELIVERIES GO BEFORE THE NOTIFICATIONS THEY BELONG TO. A delivery
        | row reaches its person only through `notification_id`; delete the parent
        | first and every attempt, failure reason and channel record is stranded
        | behind an id nothing resolves.
        |
        | ⚠️ AND `contact_verifications` IS THE ROW THAT MATTERS MOST HERE. It holds
        | the VERIFIED phone number — the one 020 actually messages, read from here
        | rather than from `users.phone` precisely because that column is a free
        | string nobody confirmed. An erasure that cleared the account and left this
        | table would leave a confirmed way to reach the person after they asked to
        | be forgotten.
        */
        $notificationIds = Notification::query()
            ->where('recipient_user_id', $userId)
            ->limit($limit)
            ->pluck('id')
            ->all();

        $deleted = 0;

        if ($notificationIds !== []) {
            $deleted += NotificationDelivery::query()
                ->whereIn('notification_id', $notificationIds)
                ->limit($limit)
                ->delete();

            if ($deleted >= $limit) {
                return $deleted;
            }

            $deleted += Notification::query()->whereIn('id', $notificationIds)->delete();

            if ($deleted >= $limit) {
                return $deleted;
            }
        }

        $deleted += NotificationPreference::query()
            ->where('user_id', $userId)
            ->limit($limit - $deleted)
            ->delete();

        if ($deleted >= $limit) {
            return $deleted;
        }

        $deleted += ContactVerification::query()
            ->where('user_id', $userId)
            ->limit($limit - $deleted)
            ->delete();

        if ($deleted >= $limit) {
            return $deleted;
        }

        /*
        | Spec 012 · US2. A subscription is a standing permission to reach a
        | person's phone; leaving it behind an erasure is leaving a live channel
        | open to somebody who asked to be forgotten — the same reason
        | `contact_verifications` is here.
        */
        return $deleted + PushSubscription::query()
            ->where('user_id', $userId)
            ->limit($limit - $deleted)
            ->delete();
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     *
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($mode !== ExpiryBehaviour::Delete) {
            return 0;
        }

        if ($category === 'push_subscription') {
            return $this->expirePushSubscriptions($before, $limit, $exemptUserIds);
        }

        if ($category !== 'notification_record') {
            return 0;
        }

        /*
        | ⚠️ `whereNotNull('read_at')` — READ ROWS ONLY, AND DROPPING IT TURNS A
        | RETENTION SWEEP INTO A JOB THAT DELETES MESSAGES NOBODY EVER SAW. An
        | unread row is a message its recipient has not opened yet; deleting it
        | makes a delivered notification into one that silently never arrived,
        | which is worse than an old feed. This condition came here with the body of
        | `PruneOldNotificationsJob`, which is deleted: `config('notifications.
        | retention_days')` was a SECOND OWNER of a duration the catalogue already
        | holds (FR-031أ), so what an operator shortened from the panel was not what
        | actually deleted.
        |
        | ⚠️ AND THE DELIVERIES GO FIRST. A delivery row reaches its person only
        | through `notification_id`; delete the parent and every attempt, failure
        | reason and channel record is stranded behind an id nothing resolves.
        */
        $ids = Notification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', $before->toDateTimeString())
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('recipient_user_id', $exemptUserIds))
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        $deleted = NotificationDelivery::query()
            ->whereIn('notification_id', $ids)
            ->limit($limit)
            ->delete();

        if ($deleted >= $limit) {
            return $deleted;
        }

        return $deleted + Notification::query()->whereIn('id', $ids)->delete();
    }

    /**
     * A device nobody has used in two years (spec 012 · US2).
     *
     * ⚠️ THE AGE IS `created_at`, NOT `last_used_at`. The second is null for every
     * subscription that never received anything — which is precisely the dead one
     * this sweep is for — and `NULL < date` is NULL on both engines, so a
     * predicate on it would silently spare exactly the rows it was written to
     * clear while reporting a clean run.
     *
     * ⚠️ AND THE HOLD EXEMPTION IS A PLAIN `whereNotIn`, WHICH IS SAFE HERE ONLY
     * BECAUSE `user_id` IS NOT NULL. On a nullable column that form spares
     * nothing (`NULL NOT IN (…)` is NULL) and the `orWhereNull` that fixes it ORs
     * at the top level unless grouped — discarding the age bound and taking the
     * whole table.
     *
     * @param  list<int>  $exemptUserIds
     */
    private function expirePushSubscriptions(CarbonImmutable $before, int $limit, array $exemptUserIds): int
    {
        return PushSubscription::query()
            ->where('created_at', '<', $before->toDateTimeString())
            ->when($exemptUserIds !== [], fn ($query) => $query->whereNotIn('user_id', $exemptUserIds))
            ->limit($limit)
            ->delete();
    }
}
