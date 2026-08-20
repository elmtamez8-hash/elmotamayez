<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Levels and badges — platform reference data (layer ب), same guard as the
 * action catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('level')->unique();
            $table->string('name_ar');
            $table->unsignedInteger('xp_threshold');
            $table->timestamps();

            // The only question ever asked of this table: "given this xp, which
            // level?" — a descending walk from the threshold.
            $table->index('xp_threshold');
        });

        Schema::create('badges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 64)->unique();
            $table->string('name_ar');
            $table->string('icon', 64)->nullable();

            /*
            | ⚠️ A CLOSED SET WITH AN EVALUATOR PER MEMBER, never a general rule
            | engine with no vocabulary. The repository's convention is that every
            | closed set is an enum; an open `rule_type` string is a column whose
            | valid values live in whatever the last person to add one remembered.
            | See Gamification\Enums\BadgeRuleType.
            */
            $table->string('rule_type', 32);
            $table->unsignedInteger('rule_value')->default(0);

            /*
            | Which action the rule counts, for the rule types that count one.
            | Text and not an FK for the same reason award_entries.action_key is:
            | an action can be retired and the badge it earned must stay readable.
            */
            $table->string('rule_action_key', 64)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
        Schema::dropIfExists('levels');
    }
};
