<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index the retention sweep walks (spec 013 · T129).
 *
 * The smallest table of the seven, and indexed for the same reason as the rest:
 * an unindexed nightly predicate is a table scan that costs nothing today and is
 * discovered the year it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
