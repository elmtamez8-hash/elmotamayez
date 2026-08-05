<?php

declare(strict_types=1);

use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Shared\Support\GuardianPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parents and guardians as first-class relations, replacing parent_child_links.
 *
 * The old table recorded who was linked to whom and nothing else: no relation
 * type, no permissions, no lifecycle. Those are not columns that can be defaulted
 * onto an existing row without a decision, so the decision is made here and
 * stated: a link that exists today is a full parent relation with every
 * permission and active status, because that is what it meant in practice.
 *
 * Ownership layer: PLATFORM-OWNED (FR-025أ). No workspace_id, deliberately — the
 * relation is between two people on the platform, and duplicating it per academy
 * would give a parent with one child at four teachers four separate consent
 * records to keep in sync. A teacher's access to these rows is gated instead by
 * ParentStudentRelationPolicy, which requires an active enrollment in that
 * teacher's own workspace (Constitution I).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_student_relations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('guardian_user_id')->constrained('users')->cascadeOnDelete();
            // Nullable: a parent can add a child before that child has an account,
            // which is the common case at signup.
            $table->foreignId('student_user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('student_name', 150);
            $table->unsignedTinyInteger('student_age')->nullable();
            $table->string('student_grade_level_slug', 100)->nullable();

            $table->string('relation_type', 16);
            $table->json('permissions');
            $table->string('status', 16);
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['guardian_user_id', 'student_user_id']);
            // Recipient resolution reads by student, filtered to active rows.
            $table->index(['student_user_id', 'status']);
        });

        $this->carryOverExistingLinks();

        Schema::dropIfExists('parent_child_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student_relations');

        Schema::create('parent_child_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('parent_id')->index();
            $table->unsignedBigInteger('child_id')->nullable()->index();
            $table->string('child_name', 150);
            $table->unsignedTinyInteger('child_age')->nullable();
            $table->string('child_grade_level_slug', 100)->nullable();
            $table->timestamps();

            $table->unique(['parent_id', 'child_id']);
        });
    }

    /**
     * Every existing link, or the migration is not done (FR-024 · SC-016).
     */
    private function carryOverExistingLinks(): void
    {
        if (! Schema::hasTable('parent_child_links')) {
            return;
        }

        $permissions = json_encode(GuardianPermission::values());
        $now = now();

        DB::table('parent_child_links')->orderBy('id')->chunk(200, function ($links) use ($permissions, $now): void {
            $rows = [];

            foreach ($links as $link) {
                $rows[] = [
                    // Keeping the original uuid means any link already handed out
                    // in a URL or an email still resolves after the migration.
                    'uuid' => $link->uuid,
                    'guardian_user_id' => $link->parent_id,
                    'student_user_id' => $link->child_id,
                    'student_name' => $link->child_name,
                    'student_age' => $link->child_age,
                    'student_grade_level_slug' => $link->child_grade_level_slug,
                    'relation_type' => RelationType::Parent->value,
                    'permissions' => $permissions,
                    'status' => RelationStatus::Active->value,
                    'revoked_at' => null,
                    'created_at' => $link->created_at ?? $now,
                    'updated_at' => $link->updated_at ?? $now,
                ];
            }

            DB::table('parent_student_relations')->insert($rows);
        });
    }
};
