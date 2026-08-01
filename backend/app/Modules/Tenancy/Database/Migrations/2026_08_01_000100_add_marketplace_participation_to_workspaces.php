<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Default false is not a style choice: existing workspaces must never be
            // published to the public marketplace retroactively (FR-001).
            $table->boolean('participates_in_marketplace')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('participates_in_marketplace');
        });
    }
};
