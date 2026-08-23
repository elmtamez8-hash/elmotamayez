<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is on a teacher's team — WORKSPACE-owned (layer 2).
 *
 * ⚠️ NO `abilities` COLUMN, AND ITS ABSENCE IS THE DESIGN. An assistant's items
 * are spatie permissions granted from the roles screen that already exists —
 * `RolePermissionMatrix` says so in its own comment, and `NFR-004` requires the
 * names to live in `Tenancy\Support\Permissions` and nowhere else. A JSON column
 * of abilities here would be a SECOND permission system: every shipped
 * `$this->authorize()` in Assessments, Courses and LiveSessions would keep
 * answering from the role while the column answered otherwise.
 *
 * The row answers the two questions the role cannot: **is this person an
 * assistant in this workspace** — the key the financial wall is built on — and
 * **on which courses**.
 *
 * ⚠️ AND IT CREATES NO MEMBERSHIP. `workspace_members` is written by
 * `AcceptInvitation` and `CreateWorkspace` alone, so the assignment rides the
 * shipped invitation and is created when one is accepted with the assistant
 * role. There is no second way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('assistant_user_id');

            /*
            | Who brought them in (FR-006 · FR-009).
            |
            | Nullable because the inviter is a person and people leave: a teacher
            | who offboards must not take the record of their assistants with
            | them, and `ON DELETE SET NULL` is not available to us here — this
            | schema declares no foreign keys, matching every other module.
            */
            $table->unsignedBigInteger('invited_by_user_id')->nullable();

            /*
            | Removal is a timestamp, never a deleted row (FR-009).
            |
            | ⚠️ AND IT IS CLAIMED BY A CONDITIONAL UPDATE — `WHERE revoked_at IS
            | NULL` — so two clicks on «إزالة» do not run the removal's side
            | effects twice. The seat idiom, the same one `captured_order_id` and
            | `StructureVersion::claim()` use.
            |
            | Work the assistant did before it stays attributed to them, which is
            | the whole of FR-009: deleting the row would leave every grading
            | record and every attendance mark pointing at a name nothing
            | resolves.
            */
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // One assignment per person per workspace. Revoking and re-inviting
            // reuses the row rather than accumulating history nobody reads —
            // `revoked_at` is cleared, and the audit lives in `activity_log`.
            $table->unique(['workspace_id', 'assistant_user_id']);

            /*
            | ⚠️ INDEXED ON THE ASSISTANT ALONE, AND THIS ONE IS READ ON EVERY
            | PERMISSION CHECK. The financial wall is a `Gate::before` keyed on
            | "does this user hold a live assignment in the current workspace",
            | and Filament asks `view`/`update` dozens of times per page. The
            | unique above leads with `workspace_id` and cannot serve it.
            */
            $table->index('assistant_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_assignments');
    }
};
