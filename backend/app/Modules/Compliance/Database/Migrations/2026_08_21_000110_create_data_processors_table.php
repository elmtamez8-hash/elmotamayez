<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who receives personal data outside our own servers — PLATFORM reference data
 * (constitution v1.2.0 §I, layer ب).
 *
 * ⚠️ `erasure_capability` IS THE ANSWER TO `FR-024`, AND IT DIFFERS PER PROCESSOR
 * — which is why it is a column and not a constant. A media host can delete a
 * video on request; a CDN's edge cache expires on its own schedule and cannot be
 * told to forget anything on demand. Recording that difference is what lets an
 * erasure report say honestly what has gone and what will lapse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_processors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('purpose_ar');
            // Where the data physically sits. A transfer out of the country is
            // the fact a regulator asks about first.
            $table->string('processing_location');

            // Which `data_categories.key` values reach them.
            $table->json('categories');

            $table->enum('erasure_capability', ['full', 'partial', 'none']);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_processors');
    }
};
