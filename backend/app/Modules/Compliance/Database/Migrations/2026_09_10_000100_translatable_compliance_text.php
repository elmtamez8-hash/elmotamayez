<?php

declare(strict_types=1);

use App\Shared\Database\TranslatableColumns;
use Database\Seeders\DataCategorySeeder;
use Database\Seeders\DataProcessorSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The register's authored prose becomes translatable.
 *
 * ⚠️ `data_processors.name` IS NOT TOUCHED, deliberately: it is a VENDOR's name
 * — a processor's trading name — and a vendor is called the same thing in every
 * language. Translating it would invite a second spelling of one company into
 * a register whose whole purpose is naming exactly who processes what.
 */
return new class extends Migration
{
    public function up(): void
    {
        TranslatableColumns::toJson('data_categories', ['label_ar' => 'label', 'purpose_ar' => 'purpose']);
        TranslatableColumns::toJson('data_processors', ['purpose_ar' => 'purpose']);

        // The backfills dated before this one returned early while the column
        // was still `label_ar` — see {@see TranslatableColumns::converted}. Now that it
        // is here, the catalogue is filled in the same pass.

        // `run()` is already `firstOrCreate` on the key — see the seeder.
        (new DataCategorySeeder)->run();
        (new DataProcessorSeeder)->run();
    }

    public function down(): void
    {
        TranslatableColumns::toStrings('data_categories', ['label_ar' => 'label', 'purpose_ar' => 'purpose']);
        TranslatableColumns::toStrings('data_processors', ['purpose_ar' => 'purpose']);
    }
};
