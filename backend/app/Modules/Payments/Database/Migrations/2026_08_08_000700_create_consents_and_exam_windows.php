<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tables from opposite ownership layers, kept in one migration because
 * neither is big enough to warrant its own and both land with US8/US9.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Platform-owned (kind أ): the consent is given TO the platform, which
         * Q-4 made the seller and the party that carries the claim. It has no
         * workspace_id for the same reason the credit account has none.
         *
         * Retention: this is a record of a legal commitment and is excluded from
         * spec 013's erasure. The exclusion is documented in docs/README.md
         * rather than only implied here.
         */
        Schema::create('terms_consents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Who signed — the student themselves, or an authorised guardian.
            $table->unsignedBigInteger('user_id');

            // Who it is about. Separate from the signer: without proving the
            // link between them, any user could sign a legal document in
            // someone else's name, and the response would confirm the id
            // belongs to a real person.
            $table->unsignedBigInteger('student_user_id');

            $table->string('document', 64);
            $table->string('version', 32);

            // TrustProxies must be configured before this column means anything:
            // unconfigured, $request->ip() returns the load balancer's address in
            // production — the same address for everyone, in the record that
            // exists to be relied on in a dispute.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('consented_at');
            $table->timestamps();

            // "Is there a current consent for this student on this document?"
            $table->index(['student_user_id', 'document', 'version']);
            $table->index(['user_id', 'consented_at']);
        });

        /*
         * Workspace-owned: a window over the teacher's own calendar.
         *
         * Dates, not timestamps, and read by comparing against a date STRING
         * rather than whereDate() — a function around the column discards this
         * index, the same fix FreezePeriod::covering() needed in 005. Where the
         * compared column is a timestamp and the bound is a date, the upper bound
         * is the START of the following day.
         */
        Schema::create('exam_mode_windows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_mode_windows');
        Schema::dropIfExists('terms_consents');
    }
};
