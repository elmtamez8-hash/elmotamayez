<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ٠٣٥ · T004 — «وافقتُ على أن تُخصَمَ حصّةٌ لأفتحَ محتوى تلك الحصّة».
     *
     * ⚠️ UNDER Payments AND NOT UNDER LiveSessions, and that is a guard
     * rather than a filing preference. `ContextIsolationTest` derives each
     * context's table list with `preg_match_all` over the `Schema::create`
     * calls in that module's own migrations (`:44-54`) — so an unlock table
     * created under LiveSessions leaves the billing context, and the
     * teacher's settlement side becomes free to query it. That is exactly
     * what FR-032 forbids.
     *
     * ⚠️ `reason` CARRIES FOUR VALUES, NOT THREE: `consent` ·
     * `subscription` · `removed_from_room` · `charged_absence`. The last
     * three write a row costing ZERO credits, and without the column saying
     * why it cost nothing, a zero row is indistinguishable from a defect to
     * whoever reads the table a month later — the same rule the ledger
     * already follows.
     *
     * ⚠️ THE UNIQUE KEY IS THE SECOND GUARD AGAINST A DOUBLE PRESS, not the
     * first: the first is the ledger's own index on an independent source
     * (`session_unlock`). Both, because the row and the entry are two writes
     * and they share ONE outer transaction.
     *
     * ⚠️ THE REVERSE READ («who unlocked this session») IS DELIBERATELY
     * UNINDEXED. Nothing in this shipment asks it; the first reader who
     * needs it adds the index.
     */
    public function up(): void
    {
        Schema::create('session_unlocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('student_user_id');
            $table->unsignedBigInteger('class_session_id');

            // Explicit, for the reason written on `credit_holds`.
            $table->unsignedBigInteger('workspace_id');

            // 1, or 0 for an automatic opening (research §R11).
            $table->integer('credits_charged')->default(0);
            $table->string('reason', 24);

            // Ties the row to its ledger entry — FR-012.
            $table->unsignedBigInteger('credit_transaction_id')->nullable();
            $table->timestamp('consented_at');

            $table->timestamps();

            $table->unique(
                ['student_user_id', 'class_session_id'],
                'session_unlocks_student_session_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_unlocks');
    }
};
