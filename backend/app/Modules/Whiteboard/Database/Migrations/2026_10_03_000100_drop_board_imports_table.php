<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A PDF is read in the teacher's browser since 2026-10-02 (owner: the server
 * converts nothing), so the server import's table has no writer. The create
 * migration no longer makes it; this drops it where it was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('board_imports');
    }

    /**
     * ⚠️ DOES NOT RESTORE THE TABLE: the create migration no longer defines it,
     * and no code reads or writes it. Its rows (finished or failed server
     * imports) are gone for good, and nothing needed them.
     */
    public function down(): void {}
};
