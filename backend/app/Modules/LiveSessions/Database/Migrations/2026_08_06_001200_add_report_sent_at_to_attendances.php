<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // When the guardian was told what this row says.
            //
            // Two things need it and neither can be derived: the job must not
            // report the same row twice if it runs again, and a correction
            // (FR-037) is only owed to someone who already received a report —
            // an override before the delay elapses simply changes what the
            // first report will say.
            $table->timestamp('report_sent_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('report_sent_at');
        });
    }
};
