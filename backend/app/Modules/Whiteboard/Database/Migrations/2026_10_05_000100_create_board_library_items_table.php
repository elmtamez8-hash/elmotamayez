<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academy's shared board library (owner decision 2026-10-05): shapes any
 * teacher shares, seen by every teacher of the same workspace, removed by the
 * one who shared it or the academy's owner.
 *
 * WORKSPACE-OWNED (constitution I): `BelongsToWorkspace` and a row in
 * `WorkspaceIsolationTest`.
 *
 * `created_by_user_id` is NULLABLE and `nullOnDelete`: the item is the
 * academy's material, so erasing the person who shared it anonymises it
 * (`WhiteboardPersonalData`) and only the owner may remove it after that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_library_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->longText('elements');
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_library_items');
    }
};
