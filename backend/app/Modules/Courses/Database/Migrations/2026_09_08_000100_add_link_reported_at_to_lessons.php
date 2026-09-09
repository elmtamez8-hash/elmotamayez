<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 032 · T005 — when the last «this video is broken» alert was sent for an
| embedded lesson (FR-020).
|
| The platform CANNOT detect a deleted video: the host answers a perfectly valid
| response and writes its message inside its own frame, and the browser forbids
| reading across origins. So the viewer is the sensor, and this column is what
| stops a hundred viewers of one broken lesson producing a hundred alerts —
| which is the road to every alert on the account being muted.
|
| ⚠️ NULLABLE, NO INDEX, AND DELIBERATELY NO `->after()`. Naming the position
| forces `INPLACE` on MySQL below 8.0.29; dropping it makes the add `INSTANT` on
| any 8.0. And `down()` is a bare `dropColumn` — correct ONLY because there is no
| index on it: SQLite's native DROP COLUMN refuses an indexed column, so the day
| an index is added it must be dropped first in its own closure, or every test in
| this repository fails on the rollback.
|
| ⚠️ AND IT IS DELIBERATELY *NOT* `$fillable` — the opposite of the rule the
| `private_session_minutes` migration beside it records, and for the reason
| `ownership_transferred_at` is not fillable either: it is CLAIMED by a
| conditional UPDATE (the `captured_order_id` idiom), and mass-assignable it
| becomes a second way to claim the window from outside the statement that owns
| it. Its three writers are named in `data-model.md` and all three use
| `forceFill()` or raw SQL.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->timestamp('link_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('link_reported_at');
        });
    }
};
