<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
| «النسخة السابقة لم تعد سارية» — false on every count. A certificate renders
| live from its row, and `RegenerateCertificate` touches neither its
| verification code nor its grant date, so the link the student shared keeps
| answering exactly as before. The template says so now, and the migration
| carries the new sentence to a database seeded before it — but only over the
| shipped text, never over an operator's own wording.
*/

function certificateRegeneratedMigration(): object
{
    return require base_path('app/Modules/Notifications/Database/Migrations/2026_09_25_000100_reword_certificate_regenerated_without_revoking.php');
}

function certificateRegeneratedRow(): MessageTemplate
{
    return MessageTemplate::query()
        ->where('type', NotificationType::CertificateRegenerated->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail();
}

it('seeds a sentence that revokes nothing', function (): void {
    $body = certificateRegeneratedRow()->body;

    expect($body)->not->toContain('لم تعد سارية')
        ->and($body)->toContain('{{ certificate_number }}')
        ->and($body)->toContain('رمز التحقّق');
});

it('rewords the shipped body on a live database, and only the shipped one', function (): void {
    $migration = certificateRegeneratedMigration();
    $row = certificateRegeneratedRow();

    $row->forceFill(['body' => $migration::SHIPPED_BODY])->save();
    $migration->up();
    expect($row->fresh()?->body)->toBe($migration::REWORDED_BODY);

    // An operator's own wording is theirs.
    $row->forceFill(['body' => 'ADMIN_EDITED_SENTINEL'])->save();
    $migration->up();
    expect($row->fresh()?->body)->toBe('ADMIN_EDITED_SENTINEL');
});

it('ships the same sentence the seeder writes', function (): void {
    // Two copies of one sentence drift at the first edit; a fresh database and a
    // migrated one must read the same words.
    expect(certificateRegeneratedMigration()::REWORDED_BODY)->toBe(certificateRegeneratedRow()->body);
});
