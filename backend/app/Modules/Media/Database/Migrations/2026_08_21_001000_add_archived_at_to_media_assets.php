<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mark an archived recording carries, and the index the sweep walks
 * (spec 013 · T129 · T133).
 *
 * ⚠️ WITHOUT THE COLUMN, `ExpiryBehaviour::Archive` NEVER CONVERGES. Its
 * predicate is "older than N days", which is true again tomorrow — so a
 * behaviour with no mark re-archives the same rows every night for ever, deletes
 * the same file at the provider every night, and counts them again each time in
 * "how many rows did I process", which makes `retention_sweep_runs` lie in the
 * same breath as it is written. Precedent, in the same words:
 * `notified_dormant_at`.
 *
 * ⚠️ AND THE ROW SURVIVES, WHICH IS THE WHOLE DIFFERENCE FROM `Delete`. A
 * recording's lesson sits in the course tree; deleting the asset row would leave
 * that lesson pointing at an id nothing resolves, with no record anywhere that a
 * retention rule — rather than a bug — is why the video is gone. Archived, the
 * row keeps the duration, the filename and the date, and `provider_asset_id` is
 * cleared because the file it named no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();

            /*
            | ⚠️ THE NULL COLUMN LEADS, exactly as `(ownership_transferred_at,
            | date_of_birth)` does in Identity: everything already archived is
            | excluded by the first column, so the range scan on the date only ever
            | walks the shrinking remainder.
            */
            $table->index(['archived_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['archived_at', 'created_at']);
            $table->dropColumn('archived_at');
        });
    }
};
