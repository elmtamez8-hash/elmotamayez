<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
| «أو طلب استرداده» pointed at a button that does not exist: every refund is an
| officer's decision in `/admin`, reached through support. The dormant-credit
| notice names that door now (owner decision 2026-09-27), and the migration
| carries the sentence to a database seeded before it — but only over the
| shipped text, never over an operator's own wording.
*/

function dormantNoticeMigration(): object
{
    return require base_path('app/Modules/Notifications/Database/Migrations/2026_09_27_000100_reword_credit_balance_dormant_to_contact_support.php');
}

function dormantNoticeRow(NotificationChannel $channel = NotificationChannel::InApp): MessageTemplate
{
    return MessageTemplate::query()
        ->where('type', NotificationType::CreditBalanceDormant->value)
        ->where('channel', $channel->value)
        ->firstOrFail();
}

it('seeds a notice that sends the student to support, not to a refund screen', function (): void {
    $body = dormantNoticeRow()->body;

    expect($body)->toContain('تواصل مع الدعم لطلب استرداد رصيدك')
        ->and($body)->not->toContain('طلب استرداده')
        // Still a reminder, never an expiry.
        ->and($body)->toContain('الرصيد لا ينتهي');
});

it('rewords the shipped body on a live database on every channel, and only the shipped one', function (): void {
    $migration = dormantNoticeMigration();
    $inApp = dormantNoticeRow();
    $whatsApp = dormantNoticeRow(NotificationChannel::WhatsApp);

    $inApp->forceFill(['body' => $migration::SHIPPED_BODY])->save();
    $whatsApp->forceFill(['body' => $migration::SHIPPED_BODY])->save();
    $migration->up();
    expect($inApp->fresh()?->body)->toBe($migration::REWORDED_BODY)
        ->and($whatsApp->fresh()?->body)->toBe($migration::REWORDED_BODY);

    // An operator's own wording is theirs.
    $inApp->forceFill(['body' => 'ADMIN_EDITED_SENTINEL'])->save();
    $migration->up();
    expect($inApp->fresh()?->body)->toBe('ADMIN_EDITED_SENTINEL');
});

it('ships the same sentence the seeder writes', function (): void {
    expect(dormantNoticeMigration()::REWORDED_BODY)->toBe(dormantNoticeRow()->body);
});
