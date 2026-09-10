<?php

declare(strict_types=1);

use App\Shared\Database\TranslatableColumns;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * `title_ar`/`body_ar` become translatable `title`/`body` on both tables.
 *
 * ⚠️ `message_templates.body` STAYS DOCUMENTATION OF AN APPROVED TEMPLATE, not
 * the text that is sent. What travels to WhatsApp is the template NAME and an
 * ordered parameter list; the Arabic sentence lives at the provider. Making the
 * column translatable changes the notification bell and changes nothing on a
 * phone — which is exactly what it changed before.
 *
 * `NotificationResource` already maps these to `title`/`body`, so the API
 * contract does not move and no frontend file mentions the old names.
 */
return new class extends Migration
{
    private const COLUMNS = ['title_ar' => 'title', 'body_ar' => 'body'];

    public function up(): void
    {
        foreach (['notifications', 'message_templates'] as $table) {
            TranslatableColumns::toJson($table, self::COLUMNS);
        }

        // The backfills dated before this one returned early while the column
        // was still `title_ar` — see {@see TranslatableColumns::converted}. Now that it
        // is here, the catalogue is filled in the same pass.

        (new NotificationTemplateSeeder)->seedMissing();
    }

    public function down(): void
    {
        foreach (['notifications', 'message_templates'] as $table) {
            TranslatableColumns::toStrings($table, self::COLUMNS);
        }
    }
};
