<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ٠٣٥ · T001 — «المحجوز» بجانبَ «المتبقّي».
     *
     * ⚠️ SIGNED, AND THE REASON NEVER SHOWS UP LOCALLY. MySQL raises
     * ERROR 1690 (BIGINT UNSIGNED value is out of range) the moment an
     * unsigned column takes part in arithmetic that goes negative, and
     * SQLite has no unsigned arithmetic to overflow — so no local test can
     * ever reproduce it. Its three neighbours (`purchased` · `consumed` ·
     * `remaining`) are signed for exactly this reason, at
     * `2026_08_08_000500_create_credit_tables.php:81-86`.
     *
     * ⚠️ AND THIS COLUMN WIDENS THE EXPOSURE RATHER THAN INHERITING IT. The
     * existing two-column arithmetic sits in a minority branch; the hold
     * guard puts `remaining - held - 1 >= …` in EVERY booking on the
     * platform — which at a zero balance is evaluated unsigned and explodes
     * on the first booking by any student with an empty balance. The
     * expression that raises it is `PlaceCreditHold`'s (T056), not the
     * existing floor's, and it carries `CAST(... AS SIGNED)` beside it.
     *
     * ⚠️ NO `available_credits` COLUMN. A second answer to a question that
     * already has two, and it drifts from `remaining - held` at the first
     * write that forgets it. Computed in PHP.
     */
    public function up(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->integer('held_credits')->default(0)->after('remaining_credits');
        });
    }

    public function down(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->dropColumn('held_credits');
        });
    }
};
