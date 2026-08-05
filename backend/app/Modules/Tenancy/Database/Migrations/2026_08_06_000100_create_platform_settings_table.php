<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational values an administrator tunes without a deploy.
     *
     * Platform-owned by definition: the device limit applies to a student account
     * that enrols with many teachers, so no single teacher can own the decision.
     * This is deliberately NOT workspaces.settings, which is the wrong layer.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            // The key IS the primary key. A surrogate id would buy nothing: there
            // is exactly one row per key and nothing ever points at one.
            $table->string('key', 64)->primary();
            $table->json('value');
            // First question in any dispute about a changed limit.
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
