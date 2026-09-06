<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| What a TEACHER produces: which shipped design their workspace uses, where the
| six fields sit on it, and — from spec 028's fifth story — a design they upload
| themselves. The shipped designs are NOT here: they are a code registry
| (`CertificateTemplateRegistry`), because nobody may write them and a catalogue
| row added by a release never reaches an existing database (this tree has paid
| for that five times: notification templates, data categories, gamification
| actions, regions, taxonomy).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_designs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();

            // The teacher's name for an uploaded design; an adopted shipped one
            // takes its name from the registry and stores none.
            $table->string('name')->nullable();

            // Exactly one of these two is set — enforced in the Action, because
            // the seeder and the Filament panel reach the model with no form.
            $table->string('system_key')->nullable();
            $table->string('image_path')->nullable();

            // null = use the registry's positions. On an UPLOADED design null
            // means "not adjusted yet", which is what makes it unselectable.
            $table->json('field_boxes')->nullable();

            /*
            | ⚠️ A NULLABLE UNIQUE COLUMN, NEVER AN `is_selected` BOOLEAN. NULL
            | does not collide with NULL, so every unselected row coexists freely
            | while one workspace can hold at most one selected design — and the
            | "obvious" form of that guard, a partial index
            | (`WHERE is_selected = 1`), IS A POSTGRES FEATURE THAT MYSQL DOES NOT
            | HAVE. Same idiom as `payments.captured_order_id`.
            */
            $table->unsignedBigInteger('selected_for_workspace_id')->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_designs');
    }
};
