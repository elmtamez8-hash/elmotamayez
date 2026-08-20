<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue of what the platform collects — PLATFORM reference data
 * (constitution v1.2.0 §I, layer ب).
 *
 * ⚠️ NO `workspace_id`, AND IT MUST NOT GAIN ONE. "The student's name" is one
 * category for the product, not one per teacher; a tenant column here would give
 * every teacher their own privacy policy and make `SC-002` — the schema compared
 * against the declared catalogue — a per-workspace question with no single
 * answer. The guard is the PLATFORM permission `compliance.registry.manage`,
 * which no workspace role holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('key', 64)->unique();
            $table->string('label_ar');
            // Plain language, not compressed legal text (FR-004). A policy a
            // parent cannot read is a policy nobody consented to.
            $table->text('purpose_ar');
            $table->string('audience', 64);

            // The required/optional split is the whole of FR-007: an optional
            // category can be withdrawn, a required one cannot.
            $table->boolean('is_required')->default(false);

            $table->string('owning_module', 64)->index();

            /*
            | ⚠️ NOT NULLABLE, AND THAT IS THE MECHANISM RATHER THAN A TIDINESS
            | RULE. These two columns are what `SC-002` compares the live schema
            | against — a category with no table and no column is a declaration
            | that matches nothing and can never fail. An earlier draft had them
            | as nullable `*_hint`, which made the criterion's own instrument
            | optional.
            */
            $table->string('table_name');
            $table->string('column_name');

            /*
            | ⚠️ `unsignedSmallInteger`, AND NEITHER FAILURE IS VISIBLE LOCALLY.
            |
            | The sweep computes a cutoff from this number. Past 65,535 days the
            | arithmetic runs off the end of the calendar: MySQL raises
            | ERROR 1441 (datetime field overflow) and kills the ENTIRE sweep —
            | not just this category — while SQLite returns NULL, so the predicate
            | matches nothing and the rows simply never expire. One environment
            | dies loudly, the other lies quietly, and the development machine is
            | the quiet one.
            |
            | 65,535 days is about 179 years. `null` means this category never
            | expires, which is a real answer: a certificate does not.
            */
            $table->unsignedSmallInteger('retain_days')->nullable();
            $table->enum('expiry_behaviour', ['delete', 'anonymise', 'archive'])->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_categories');
    }
};
