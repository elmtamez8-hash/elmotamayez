<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One notice from a teacher to a defined slice of their students (010 · FR-042).
 *
 * ⚠️ `published_at` IS CLAIMED BY A CONDITIONAL UPDATE, not stamped after a read.
 * Publishing fans out to every student in scope, so two taps on a slow connection
 * both read null, both write, and three hundred families are told twice about one
 * change of time. The seat idiom — `captured_order_id`, `StructureVersion::claim()`
 * — and never `lockForUpdate()`, a no-op on SQLite.
 *
 * ⚠️ «GROUPS» ARE OUT OF SCOPE, DECLARED (ق-٥ · ت-٣). `FR-042` lists a group as a
 * possible scope and no group entity exists anywhere in this product; building one
 * here would be a membership model, a management screen and a permission — a
 * feature, smuggled in as an enum value. The three scopes that ship are the three
 * that already have an owner: the workspace, a course, and one session.
 *
 * ⚠️ AND NO ATTACHMENTS COLUMN (ت-٤). `FR-042` mentions them; nothing else in the
 * requirement, the criteria or the screens depends on one. When they are asked
 * for they go through `RequestUploadTicket`, which is what the chat attachment
 * already does — a bare `attachments` json column here would be the second upload
 * path, outside the provider resolver and outside the retention sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('author_user_id')->index();

            // `all` · `course` · `session`. `scope_id` is null for `all` and an
            // internal id otherwise, resolved from a uuid INSIDE the Action after
            // the workspace check — a bare id in a request body is an identity
            // probe (NFR-001أ), and this one would address another teacher's course.
            $table->string('scope', 16);
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->text('body');
            $table->boolean('is_urgent')->default(false);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            // The publisher's list: their workspace, newest first.
            $table->index(['workspace_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
