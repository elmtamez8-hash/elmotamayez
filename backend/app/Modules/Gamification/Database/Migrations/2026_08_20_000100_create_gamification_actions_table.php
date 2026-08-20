<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The action catalogue — platform reference data (constitution v1.2.0 §I, layer ب).
 *
 * No workspace_id, and write access is a PLATFORM permission: what an action is
 * worth orders the subject, grade and platform boards, so a teacher who could
 * raise "attended a session" could seat their own students at the top of all
 * three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gamification_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 64)->unique();
            $table->string('name_ar');

            /*
            | ⚠️ SIGNED ON PURPOSE, BOTH OF THEM.
            |
            | A negative action ("payment overdue") is a ROW, not a branch in the
            | code. Making these unsigned would force every penalty to be special-
            | cased at the one place awards are written, which is the place that
            | must stay simple — and it would put the sign in code the operator
            | cannot see from the panel they are editing values in.
            */
            $table->integer('xp')->default(0);
            $table->integer('coins')->default(0);

            /** How many times a day this action may pay. Null = uncapped (FR-006). */
            $table->unsignedSmallInteger('daily_cap')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gamification_actions');
    }
};
