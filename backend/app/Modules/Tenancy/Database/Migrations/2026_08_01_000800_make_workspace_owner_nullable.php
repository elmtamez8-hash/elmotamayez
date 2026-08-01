<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform workspace has no owner (FR-013).
 *
 * Every academy workspace is created by the person who owns it, so the column was
 * NOT NULL. The workspace independent teachers land in is created by the system,
 * and inventing an owner for it — the first super admin, a synthetic user —
 * records a relationship that does not exist. Null is the honest answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_user_id')->nullable(false)->change();
        });
    }
};
