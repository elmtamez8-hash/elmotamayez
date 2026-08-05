<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a phone number or address really belongs to the account (FR-041).
 *
 * The code is stored hashed. A table of plaintext one-time codes is a password
 * table under another name, and it would be readable by anyone who can read the
 * database — including from a backup.
 *
 * No consumer at launch: the in-app channel needs no verified contact. The path
 * is built and tested now so the first external channel is a class, not a class
 * plus a verification flow plus a rate limiter.
 *
 * Ownership layer: PLATFORM-OWNED. Guard is user_id; there is no read endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('contact_value', 190);

            $table->string('code_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'channel']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_verifications');
    }
};
