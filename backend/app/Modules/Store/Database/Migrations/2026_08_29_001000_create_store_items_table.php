<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T029 — what a teacher sells: a book or a set of notes, digital or
 * printed.
 *
 * ⚠️ `stock` IS A **SIGNED** `integer`, AND ONLY MySQL WOULD EVER TELL YOU WHY.
 * `ClaimStock` compares `stock - :qty` and the credit ledger has already paid
 * for the unsigned version of this: arithmetic that goes below zero on an
 * `unsignedInteger` column raises **ERROR 1690, BIGINT UNSIGNED value is out of
 * range** — while SQLite has no unsigned arithmetic to overflow, so no local
 * test can reproduce it and the failing environment is the one nobody runs the
 * suite in.
 *
 * ⚠️ AND IT IS NULLABLE, WHICH THE CLAIM MUST BRANCH ON BEFORE IT READS IT.
 * `null` means "digital, there is no stock" — and `stock >= :qty` against NULL
 * is NULL, so a claim written without the `kind` branch first matches zero rows
 * and reports «نفد المخزون» about a product that cannot run out.
 *
 * `course_id` is nullable: a book may hang off a course (FR-009 opens the door
 * from the course page) or stand alone in the store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('course_id')->nullable();

            // `digital` | `physical`. Not an enum column: the same reason every
            // other status in this tree is a string — a value added in code
            // must not need a migration to be storable.
            $table->string('kind', 16);

            $table->string('title');
            $table->text('description')->nullable();

            // ⚠️ STORED, NOT DERIVED FROM `description`. The list shows it, and
            // rendering a thousand Markdown bodies per request is processor cost
            // rather than query cost — so the query budget passes green while
            // the 800ms target fails, which is the worst shape a limit can have.
            $table->string('excerpt', 200)->nullable();

            // Minor units, integer, for the reason settlement money is: Laravel's
            // `decimal:2` cast returns a STRING, so every sum goes through a
            // float. Tolerable on an order total, not on what a teacher is owed.
            $table->bigInteger('price_minor');
            $table->char('currency', 3);

            // See the two warnings above. Signed and nullable, both deliberate.
            $table->integer('stock')->nullable();
            $table->bigInteger('shipping_fee_minor')->nullable();

            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // ⚠️ `id` IN THE TAIL, AND IT IS NOT DECORATION. The list is ordered,
            // and without a tie-breaker in the index MySQL sorts the result set
            // by hand — a `filesort` on every page of every store.
            $table->index(['workspace_id', 'is_active', 'id']);
            $table->index(['workspace_id', 'kind']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_items');
    }
};
