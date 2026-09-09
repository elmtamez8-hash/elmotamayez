<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 052 — «تذكير موعد» finally has a sender, and a sender needs a mark.
|
| ⚠️ THE COLUMN IS THE WHOLE REASON THE SWEEP CONVERGES. «starting within the
| next N minutes» is true again on the next pass, and the pass runs every few
| minutes — so without a mark every seat holder is reminded again, and again,
| until the lesson begins. That is `notified_dormant_at`'s reasoning word for
| word, and `updated_at` is unusable for it there and here for the same reason:
| it moves for every unrelated write to the row.
|
| ⚠️ AND IT IS STAMPED BEFORE THE DISPATCH, NEVER AFTER. A reminder lost to a
| worker that died mid-pass is one student who does not get a nudge; a reminder
| re-sent on every pass because the stamp came last is a phone buzzing every five
| minutes — and a family that mutes the channel over it loses the absence alert
| with it.
|
| Nullable with no default: null means «not yet», which is the state of every
| session already in the database, and the sweep's own window is what stops it
| reaching backwards into lessons that are already over.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('seats_frozen_at');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
