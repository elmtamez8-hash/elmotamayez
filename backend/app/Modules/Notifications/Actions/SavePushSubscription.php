<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Channels\WebPushChannel;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Support\NotificationCategory;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use Illuminate\Support\Str;

/**
 * Register a device, and — on the first one — say what it is for.
 *
 * ⚠️ `upsert()` ON `(user_id, endpoint_hash)`, NEVER `updateOrCreate`. That helper
 * is `firstOrNew` + `save`: a read, then a write, with a gap between them. Two
 * tabs re-registering at once is not an edge case here — it is what a service
 * worker does on every visit — so the check-then-write form means a 500 on the
 * happy path of the feature. The unique index is the guard, and one statement is
 * both the check and the write.
 *
 * ⚠️ AND `upsert()` BOOTS NO MODEL, so `HasUuid` never fires and `$timestamps`
 * never runs. `uuid`, `created_at` and `updated_at` are passed EXPLICITLY. On
 * MySQL a missing NOT NULL uuid is downgraded to a warning and `''` is stored,
 * after which every later row on the platform collides with it — the defect
 * `CreditLedger::writeEntry()` already carries a paragraph about.
 *
 * ⚠️ THE PREFERENCE ROWS ARE THE OTHER HALF, AND WITHOUT THEM NOTHING IS EVER
 * DELIVERED. `NotificationType::defaultChannels()` deliberately does not name
 * `Push` (see the class docblock of {@see WebPushChannel}),
 * and `PreferenceResolver` REPLACES the defaults with a stored row rather than
 * filtering them — so subscribing has to write those rows or the channel is
 * switched on for nobody.
 */
class SavePushSubscription extends Action
{
    /**
     * The subjects a push is for: a lesson about to start, money that blocks one,
     * and the account itself.
     *
     * ⚠️ READ FROM `NotificationCategory`, NOT WRITTEN OUT AS A FOURTH LIST OF
     * TYPES. A type added to «الحصص والمواعيد» tomorrow is covered here without
     * anyone remembering this file; a literal list would be a second answer that
     * diverges silently at the first addition.
     *
     * `Study`, `Achievements`, `Messages` and `Settlement` are left off on
     * purpose: several a week on a lock screen is how a person mutes the app, and
     * they take the attendance alert with them when they do.
     *
     * @return list<NotificationCategory>
     */
    public static function pushedCategories(): array
    {
        return [
            NotificationCategory::Sessions,
            NotificationCategory::Balance,
            NotificationCategory::Account,
        ];
    }

    public function handle(User $user, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        $now = now();

        PushSubscription::query()->upsert(
            [[
                'uuid' => (string) Str::orderedUuid(),
                'user_id' => $user->getKey(),
                'endpoint' => $endpoint,
                'endpoint_hash' => PushSubscription::hashOf($endpoint),
                'p256dh' => $p256dh,
                'auth' => $auth,
                'user_agent' => $userAgent,
                'last_used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'endpoint_hash'],
            // ⚠️ `uuid`, `user_id` and `created_at` are NOT here. Re-registering
            // is the same device saying its keys rotated, not a new row: rewriting
            // the uuid would break anything that ever referenced it, and rewriting
            // `created_at` would make a two-year-old subscription permanently
            // invisible to the retention sweep.
            ['endpoint', 'p256dh', 'auth', 'user_agent', 'updated_at'],
        );

        $this->seedPreferences($user);
    }

    /**
     * Turn push on for the three subjects it is for — without ever overwriting a
     * choice the person already made.
     *
     * ⚠️ `insertOrIgnore`, AND CREATE-IF-ABSENT IS THE WHOLE POINT. A service
     * worker re-registers on every visit, so an `upsert` here would resurrect
     * push into a type the user muted last week, every single time they opened
     * the app — a mute that will not stay muted, with nothing to blame.
     *
     * ⚠️ AND THE ROW CARRIES THE DEFAULTS PLUS PUSH, NOT `['push']` ALONE. A
     * stored preference REPLACES the defaults, so a row naming push only would
     * show the settings grid with in-app unticked for thirty types — while the
     * feed keeps receiving them, because the notification RECORD is written
     * before any channel is consulted. A screen lying about a mute that is not
     * real.
     */
    private function seedPreferences(User $user): void
    {
        $now = now();
        $rows = [];

        foreach (self::pushedCategories() as $category) {
            foreach ($category->types() as $type) {
                $rows[] = [
                    'uuid' => (string) Str::orderedUuid(),
                    'user_id' => $user->getKey(),
                    'type' => $type->value,
                    // No `array` cast without a model behind the write.
                    'channels' => json_encode($this->channelsFor($type), JSON_THROW_ON_ERROR),
                    'digest_window_minutes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // One statement for all of them; the unique (user_id, type) index decides
        // which land. Sixty round trips on a path that runs on every visit is the
        // alternative.
        NotificationPreference::query()->insertOrIgnore($rows);
    }

    /** @return list<string> */
    private function channelsFor(NotificationType $type): array
    {
        $channels = array_map(
            static fn (NotificationChannel $channel): string => $channel->value,
            $type->defaultChannels(),
        );

        $channels[] = NotificationChannel::Push->value;

        return array_values(array_unique($channels));
    }
}
