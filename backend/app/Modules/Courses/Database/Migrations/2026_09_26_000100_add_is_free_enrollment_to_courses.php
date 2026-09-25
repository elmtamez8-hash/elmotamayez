<?php

declare(strict_types=1);

use App\Shared\Contracts\SubscriptionDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A course is free ONLY when its teacher says so (owner decision 2026-09-25).
 *
 * ⛔ FREE WAS INFERRED, AND THE INFERENCE BECAME WRONG THE DAY THE PRICE FIELD
 * LEFT THE FORM. It read «`price_minor` is 0 and no sellable plan reaches it» —
 * and once a course is sold through plans only, every new course is born with
 * `price_minor = 0`, so it read as free from creation until its teacher got
 * round to a plan. Students could walk into a course nobody had decided to give
 * away.
 *
 * ⚠️ THE BACKFILL KEEPS TODAY EXACTLY AS IT IS. Every course that the OLD rule
 * calls free right now gets the flag, and nothing else does, so no live course
 * opens or closes on deploy. The old rule is asked through the same contract
 * method it used (`hasSellablePlanFor`), not re-derived here — a second spelling
 * of «a sellable plan reaches it» is the divergence spec 036 already paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('is_free_enrollment')->default(false)->after('currency');
        });

        $this->backfill();
    }

    /**
     * Public so the backfill can be measured against rows a test writes after
     * the schema already exists.
     */
    public function backfill(): void
    {
        $directory = app(SubscriptionDirectory::class);

        DB::table('courses')
            ->where('price_minor', 0)
            ->where('is_free_enrollment', false)
            ->orderBy('id')
            ->chunkById(200, function ($courses) use ($directory): void {
                $free = [];

                foreach ($courses as $course) {
                    if (! $directory->hasSellablePlanFor((int) $course->id)) {
                        $free[] = (int) $course->id;
                    }
                }

                if ($free !== []) {
                    DB::table('courses')->whereIn('id', $free)->update(['is_free_enrollment' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('is_free_enrollment');
        });
    }
};
