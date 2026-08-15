<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The record of one upload: what was asked for, what happened, and why each row
| that failed did.
|
| ⚠️ THE REPORT IS A COLUMN, NOT A DERIVATION. Nothing else in the schema
| remembers that line 47 named a concept the teacher does not have — the row was
| never written, so there is no row to ask. A report rebuilt later from the
| questions that DID import can only say how many are there, which is the one
| number the teacher can already count. FR-007 asks for the other half.
|
| And it is `json` rather than a `question_import_rows` table on purpose: it is
| written once by the job, read by one screen, and never queried across imports.
| A table would be ten thousand rows per upload indexed for nobody.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('uploaded_by');

            $table->string('original_filename');
            $table->string('stored_path');

            // Chosen at upload and applied without asking again (Q7). A job that
            // stops to ask a question is a job that hangs for ever.
            $table->string('duplicate_policy', 16);

            $table->string('status', 16)->default('queued');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // [{ line: 47, reason: '...', content: '...' }, ...] — failures and
            // skips only. Listing the successes would make a 10,000-row file's
            // report bigger than the questions it created.
            $table->json('report')->nullable();

            // Why the whole run stopped, when it did. Distinct from a row's
            // reason: a file that is not a CSV at all produces no rows to report.
            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The teacher's import list, newest first.
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_imports');
    }
};
