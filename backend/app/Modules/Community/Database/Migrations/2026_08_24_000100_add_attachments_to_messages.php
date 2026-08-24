<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message can carry a picture or a voice note (spec 010 · `FR-060`).
 *
 * ⚠️ `body` BECOMES NULLABLE, AND THE RULE MOVES TO THE ACTION. A voice note has
 * no text and forcing `''` would make «empty message» and «message that is only a
 * recording» the same row — indistinguishable to every reader, every export and
 * every moderation view. The constraint that replaces it is «text or attachment,
 * never neither», and it lives in `PostMessage` because that is the single entry
 * point the seeders, the panel and the API all share.
 *
 * ⚠️ AND THE ASSET IS REFERENCED, NEVER EMBEDDED. `media_assets` already carries
 * the provider, the status, the mime type and — since 013 — `archived_at` and
 * `retain_until`, so a chat attachment inherits the retention sweep, the
 * provider resolution and the deletion path for free. A `path` column here would
 * be a second file store with none of that, and 013's rule about a raw path
 * column outliving its own retention would apply word for word.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->text('body')->nullable()->change();

            /*
            | ⚠️ NO FOREIGN KEY, MATCHING EVERY OTHER REFERENCE TO `media_assets`
            | IN THIS PRODUCT. Assets are archived and deleted by the retention
            | sweep on their own schedule; a cascade would take the message with
            | the picture, and a restrict would stop the sweep. The reader handles
            | a missing asset — which is also what an expired one looks like.
            */
            $table->unsignedBigInteger('media_asset_id')->nullable()->after('body');
        });

        Schema::table('messages', function (Blueprint $table): void {
            // Answers «is this asset already on a message?», which is how a
            // second send cannot claim the same upload twice.
            $table->index('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['media_asset_id']);
            $table->dropColumn('media_asset_id');
        });
    }
};
