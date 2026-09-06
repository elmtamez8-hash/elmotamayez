<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Removes a complete feature that no client ever reached.
|
| `certificate_templates` shipped in 004 with a table, a model, a controller, two
| form requests, a resource and FIVE routes. Measured before deleting: zero files
| under `frontend/src` name any of them, `certificates.template_id` had exactly
| one writer (`IssueCertificate`) and ZERO readers, and `html_template` held raw
| HTML with `{{student_name}}` markers for which no renderer exists anywhere in
| the tree. It was a gallery nobody could open.
|
| It is deleted rather than built upon because the screen that would render it is
| `/certificates/verify/{code}` — public and unauthenticated. Rendering
| teacher-authored HTML there would put a teacher's keyboard on the most exposed
| page in the product, which this codebase forbids in as many words (see the
| Markdown rule: authored text is never stored as HTML).
|
| Spec 028 replaces it with a code registry of shipped designs plus a
| `certificate_designs` table for what a teacher actually produces.
*/
return new class extends Migration
{
    public function up(): void
    {
        /*
        | `template_id` carries NO index (see the 004 create migration), so
        | `dropColumn` alone is enough. Where a column IS indexed, SQLite's native
        | ALTER TABLE ... DROP COLUMN refuses it and the index must be dropped
        | first, in its own statement — MySQL discards it silently and only SQLite
        | says so.
        */
        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropColumn('template_id');
        });

        Schema::dropIfExists('certificate_templates');
    }

    /*
    | ⚠️ THIS ROLLBACK RESTORES THE STRUCTURE AND NOT ONE ROW. Every template a
    | workspace had written is gone; the table comes back empty and the column
    | comes back null for every certificate. An empty table reads as data and
    | holds none, so anything relying on a rollback here is relying on nothing.
    */
    public function down(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('name');
            $table->longText('html_template');
            $table->json('defaults')->nullable();
            $table->timestamps();
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->unsignedBigInteger('template_id')->nullable();
        });
    }
};
