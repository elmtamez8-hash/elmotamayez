<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| `payment_reminder` was deleted from the enum on 2026-09-24 (owner decision:
| the balance ladder replaced it in 006 and nothing ever sent it). Its rows
| are deleted with it, because every reader passes the column through
| `NotificationType::from()` and a value the enum no longer has throws.
*/

function paymentReminderRemovalMigration(): object
{
    return require base_path('app/Modules/Notifications/Database/Migrations/2026_09_24_000100_delete_payment_reminder_type.php');
}

it('is no longer a notification type', function (): void {
    expect(NotificationType::tryFrom('payment_reminder'))->toBeNull();
});

it('deletes every row naming the retired type and leaves the others', function (): void {
    $user = User::factory()->create();

    // Written raw, the way a pre-removal database holds them — the models would
    // refuse to read these back, which is the point of the migration.
    $notificationId = DB::table('notifications')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'recipient_user_id' => $user->getKey(),
        'type' => 'payment_reminder',
        'title' => json_encode(['ar' => 'تذكير']),
        'body' => json_encode(['ar' => 'نص']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('notification_deliveries')->insert([
        'uuid' => (string) Str::uuid(),
        'notification_id' => $notificationId,
        'channel' => 'in_app',
        'status' => 'delivered',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('notification_preferences')->insert([
        'uuid' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'type' => 'payment_reminder',
        'channels' => json_encode(['in_app']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('message_templates')->insert([
        'uuid' => (string) Str::uuid(),
        'key' => 'payment_reminder.in_app',
        'type' => 'payment_reminder',
        'channel' => 'in_app',
        'title' => json_encode(['ar' => 'تذكير']),
        'body' => json_encode(['ar' => 'نص']),
        'variables' => json_encode([]),
        'provider_approval_status' => 'not_required',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $survivors = DB::table('message_templates')->where('type', '!=', 'payment_reminder')->count();

    paymentReminderRemovalMigration()->up();

    foreach (['notifications', 'notification_preferences', 'message_templates'] as $table) {
        expect(DB::table($table)->where('type', 'payment_reminder')->count())->toBe(0, $table);
    }

    expect(DB::table('notification_deliveries')->where('notification_id', $notificationId)->count())->toBe(0)
        ->and(DB::table('message_templates')->count())->toBe($survivors)
        ->and(Notification::query()->count())->toBe(0);
});
