<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 021 · T041 — the group.
|
| A scheduled run of ONE course. It owns no content and no money: the purchase
| stays on the course, the progress stays on the enrolment, and nothing in this
| table has a price. That is what makes a transfer lose nothing (FR-029).
|
| ⚠️ `members_count` IS A COLUMN, NOT A QUERY. Capacity is claimed with one
| atomic conditional UPDATE (`WHERE capacity IS NULL OR members_count < capacity`)
| — the seat idiom from 005. `count()` then `insert()` is the definition of the
| race, and `lockForUpdate()` is a no-op on SQLite, so a test written around it
| passes locally and proves nothing about the MySQL this ships to.
|
| ⚠️ AND THERE IS NO DELETE. Archiving is the alternative (FR-035), and
| deliberately not `SoftDeletes`: a soft delete puts the row behind a global
| scope, which is exactly where the teacher's own screen and the audit cannot see
| the thing they just acted on — the `hidden_at` reasoning from 010.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohorts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('course_id')->index();
            $table->string('name', 120);
            $table->text('description')->nullable();
            // `null` = no ceiling. A default is not a ceiling, and the API says
            // so: a group with no declared capacity answers `seats_left: null`,
            // never a number — "unlimited" and "twenty free" are different
            // promises to a student choosing between two groups.
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('members_count')->default(0);
            $table->string('status', 20)->default('open');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'name']);
            $table->index(['workspace_id', 'course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohorts');
    }
};
