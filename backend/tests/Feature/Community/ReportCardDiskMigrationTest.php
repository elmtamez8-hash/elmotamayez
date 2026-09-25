<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| The migration that takes report-card PDFs off the public disk. It runs once on
| a database that already holds them, so the fixture is a media row pointing at
| `public` with its file there — exactly the production shape of 2026-09-25.
*/

function reportCardMediaRow(string $collection): int
{
    return (int) DB::table('media')->insertGetId([
        'model_type' => 'report_card',
        'model_id' => 1,
        'uuid' => (string) Str::uuid(),
        'collection_name' => $collection,
        'name' => 'card',
        'file_name' => 'report-card-x.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 4,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'order_column' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function runReportCardDiskMigration(): void
{
    $migration = require base_path('app/Modules/Community/Database/Migrations/2026_09_25_000300_move_report_card_pdfs_off_public_disk.php');
    $migration->up();
}

it('moves an existing report-card PDF from the public disk to local', function (): void {
    Storage::fake('public');
    Storage::fake('local');

    $id = reportCardMediaRow('report_card_pdf');
    Storage::disk('public')->put("{$id}/report-card-x.pdf", '%PDF');

    runReportCardDiskMigration();

    expect(Storage::disk('local')->get("{$id}/report-card-x.pdf"))->toBe('%PDF')
        ->and(Storage::disk('public')->exists("{$id}/report-card-x.pdf"))->toBeFalse()
        ->and(DB::table('media')->where('id', $id)->value('disk'))->toBe('local');
});

it('leaves other collections where they are, and is a no-op on a second run', function (): void {
    Storage::fake('public');
    Storage::fake('local');

    $other = reportCardMediaRow('article_cover');
    Storage::disk('public')->put("{$other}/report-card-x.pdf", 'img');

    runReportCardDiskMigration();
    runReportCardDiskMigration();

    expect(DB::table('media')->where('id', $other)->value('disk'))->toBe('public')
        ->and(Storage::disk('public')->exists("{$other}/report-card-x.pdf"))->toBeTrue();
});
