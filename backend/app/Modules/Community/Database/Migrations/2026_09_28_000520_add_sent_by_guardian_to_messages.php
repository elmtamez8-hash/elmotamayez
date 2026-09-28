<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a message on the student's side was typed by their guardian
 * (2026-09-28 — a guardian writes AS the child, in the child's thread).
 *
 * ⚠️ STORED, NOT DERIVED. «Is this sender an authorised guardian of the student»
 * is a question about TODAY's relation; the label under an old message is a fact
 * about the day it was sent. Derived, a revoked relation would silently re-label
 * the parent's messages as the teacher's side — the one misreading the teacher's
 * screen must never make. Every row before this date is `false`, which is true:
 * no guardian could write into a private thread until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('sent_by_guardian')->default(false)->after('sender_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('sent_by_guardian');
        });
    }
};
