<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt on one channel (FR-038). Several rows per notification.
 *
 * There is deliberately no phone/email column. FR-040 keeps contact details away
 * from anyone without permission, and the cheapest way to guarantee that is for
 * the log not to hold them: the channel reads the destination off the user at
 * send time and never copies it here.
 *
 * Ownership layer: BRIDGE — follows its notification, no direct read path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();

            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_reason', 500)->nullable();

            // Set when quiet hours push an external, non-mandatory message past
            // the window (FR-032). The job itself carries the delay; this column
            // is what makes the deferral visible in the admin log.
            $table->timestamp('deferred_until')->nullable();

            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('notification_id');
            $table->index(['status', 'deferred_until']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
