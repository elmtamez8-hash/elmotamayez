<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What produced this notification, as an indexable key (010 · FR-046).
 *
 * ⚠️ `FR-046` FORBIDS STORING THE COUNT, NOT STORING A KEY TO COUNT BY. «How many
 * were told, and how many read it» must be true at the moment it is read — a
 * stored counter drifts the first time a notification is deleted and then reports
 * a readership larger than the audience, permanently. So the numbers are counted
 * live, and this is what makes counting them cheap.
 *
 * ⚠️ WITHOUT IT THE COUNT IS `JSON_EXTRACT(payload, '$.announcement_uuid')`. A
 * function around a column throws away every index it might have used — on the
 * fastest-growing table in the product, twice per row, inside a LIST. And on the
 * five rows a local SQLite fixture holds it is instantaneous and green.
 *
 * The index carries `read_at` as its third column so both counters — the audience
 * and the readers — are answered from the same index without touching a row.
 *
 * Nullable, because every notification written before this migration had no
 * source and the overwhelming majority written after it still will not: a source
 * is what a fan-out has and a single addressed message does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('source_type', 64)->nullable()->after('subject_user_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

            $table->index(['source_type', 'source_id', 'read_at'], 'notifications_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_source_index');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
