<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T118 — who wants the numbers posted to them (FR-045).
 *
 * ⚠️ THIS FILE IS WHY `Analytics` LEAVES `PersonalDataContractCoverageTest`'s
 * EXEMPTION LIST IN THE SAME CHANGE. That list carried «Analytics — has no
 * `Schema::create` of its own», which stops being true here; an exemption whose
 * stated reason has expired is a guard that passes over a lie.
 *
 * `last_sent_on` is stamped BEFORE the send, the `notified_dormant_at` shape: a
 * lost report beats one every night for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The metrics this person asked for, by key. A json column rather than
            // a pivot: the list is read whole, written whole, and never joined.
            $table->json('metric_keys');

            $table->string('cadence', 16)->default('weekly');
            $table->date('last_sent_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The sweep's whole predicate.
            $table->index(['is_active', 'cadence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_subscriptions');
    }
};
