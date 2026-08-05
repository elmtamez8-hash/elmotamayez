<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-owned, both of them: deliberately no workspace_id.
     *
     * A copy per workspace would mean the device limit applies once per teacher —
     * that is, not at all, since a student need only enrol with a second teacher
     * to buy another slot.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->index();
            // Hashed, never raw: matching needs only the digest, and the raw
            // signals widen the blast radius of any dump.
            $table->string('fingerprint_hash', 64);
            $table->string('label');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Per user, not global: the same browser build on two people's
            // machines is two devices, which is the behaviour we want.
            $table->unique(['user_id', 'fingerprint_hash']);
        });

        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->id();
            // Handed to the client at login so it can ask why it was signed out
            // after the token is already gone.
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('device_id')->index();
            // personal_access_tokens.id. Null for a Filament panel session, which
            // has no token.
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('status', 16)->default('active');
            $table->string('ended_reason', 32)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // Serves the eviction query in one pass: distinct devices among a
            // user's live sessions, and the oldest of them.
            $table->index(['user_id', 'status', 'device_id', 'created_at'], 'auth_sessions_eviction_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('devices');
    }
};
