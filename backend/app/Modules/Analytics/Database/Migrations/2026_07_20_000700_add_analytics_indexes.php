<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index(['workspace_id', 'status'], 'enrollments_workspace_status_index');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->index(['workspace_id', 'status', 'passed'], 'attempts_workspace_status_passed_index');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('enrollments_workspace_status_index');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('attempts_workspace_status_passed_index');
        });
    }
};
