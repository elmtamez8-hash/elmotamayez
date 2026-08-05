<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-factor state, in its own table rather than four more columns on `users`.
     *
     * The same rule as student_profiles applied to a concern instead of a role:
     * these are null for every account that has not enabled 2FA, which is most of
     * them, and `users` is read on every authenticated request.
     *
     * The first two column names are not our choice — they are what Filament's
     * app-authentication contract reads. Honouring them is what makes one
     * enrolment cover both the panel and the API.
     */
    public function up(): void
    {
        Schema::create('user_security_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            // Confirmed, not merely generated: a secret nobody has proved they can
            // read protects nothing, and treating it as enabled would lock the
            // owner out of their own account.
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('two_factor_required_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_security_settings');
    }
};
