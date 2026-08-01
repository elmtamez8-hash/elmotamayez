<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('teacher_profile_id')->nullable()->index();

            $table->string('status')->default('draft')->index();
            $table->unsignedTinyInteger('current_step')->default(1);

            // The wizard's answers, kept as one document rather than 15 columns.
            // They are draft input until submission promotes them onto the profile,
            // and a half-filled application should not shape the profile schema.
            $table->json('step_data')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            // One live application per user: re-applying edits the existing row so
            // the reviewer never sees two versions of the same person.
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_applications');
    }
};
