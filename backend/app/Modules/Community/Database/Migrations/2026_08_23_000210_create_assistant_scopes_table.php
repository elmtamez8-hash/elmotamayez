<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which courses an assistant is confined to (FR-004).
 *
 * ⚠️ NO ROWS MEANS EVERY COURSE, not "no courses". The restriction is opt-in:
 * an assistant with an empty scope works across the whole workspace, which is
 * what an assignment created by accepting an invitation starts as. A column
 * meaning "unrestricted" would be a second way to express the same state, and
 * the two would disagree the first time one of them was written and the other
 * was not.
 *
 * ⚠️ NO `workspace_id` AND NO `uuid`, BOTH DELIBERATE. The row is reachable only
 * through its assignment, which carries the tenant key and the global scope; and
 * it appears in no payload — the whole sibling list is replaced by one
 * `PUT /manage/assistants/{assignment}/scope`, so nothing ever addresses a scope
 * row from outside. That makes the join MANDATORY: a bare
 * `AssistantScope::query()` is unscoped by construction, and every read here
 * goes through the relation or through `EloquentAssistantScopeDirectory`.
 *
 * ⚠️ AND A PRIVATE CONVERSATION CARRIES NO COURSE, so the scope is evaluated on
 * one by intersecting it with the STUDENT'S ENROLMENTS. Without that, an
 * assistant confined to a single course reads every private conversation in the
 * workspace — the restriction holding on the surfaces that name a course and
 * silently absent from the one that does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_assignment_id');
            $table->unsignedBigInteger('course_id');
            $table->timestamps();

            $table->unique(['assistant_assignment_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_scopes');
    }
};
