<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wording of every message, editable from the admin panel (FR-036).
 *
 * The split from NotificationType is the point: the type is code (a listener asks
 * for it by name, PHPStan checks it), the text is data (someone changes a comma
 * without a deploy). Created before notification_deliveries because that table
 * points at this one.
 *
 * Ownership layer: PLATFORM-OWNED. Templates are the platform's voice, not an
 * academy's — a teacher does not get to reword the security alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('key', 64);
            $table->string('type', 64);
            $table->string('channel', 32);

            $table->string('title_ar', 200);
            $table->text('body_ar');

            // Names the body may interpolate. Sending with any of these missing is
            // refused rather than rendered as an empty gap (FR-037).
            $table->json('variables')->nullable();

            // WhatsApp and SMS providers vet templates before they may be sent.
            // Tracked, not enforced by us: approval is a human process outside the
            // system, and the column is how the system learns its outcome.
            $table->string('provider_approval_status', 16)->default('not_required');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'channel']);
            $table->unique('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
