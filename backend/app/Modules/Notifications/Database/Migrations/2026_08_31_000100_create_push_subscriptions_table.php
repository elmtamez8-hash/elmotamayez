<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One browser profile's permission to wake one device (spec 012 · US2 · T066).
 *
 * Ownership layer: PLATFORM-OWNED (Constitution I, kind أ). ⚠️ NO `workspace_id`,
 * AND NOT AS AN OVERSIGHT. A push subscription is a property of a DEVICE somebody
 * owns, not of a teacher's classroom: give it a workspace and one phone becomes
 * three rows for a student who studies with three teachers, each one pushed
 * separately, so the same alert arrives three times — and `PlatformOwnershipTest`
 * exists to fail the build in both directions.
 *
 * ⚠️ THE UNIQUE KEY IS `(user_id, endpoint_hash)`, NEVER `endpoint_hash` ALONE.
 * A browser profile holds ONE subscription per origin, and a guardian and their
 * child share a phone — the family shape spec 013 was built around. With a global
 * unique, whoever subscribes second STEALS the first one's row: the first account
 * silently stops receiving everything, `security_alert` included, with no error
 * and no log line. The other half is an attacker: authenticated, holding somebody
 * else's endpoint, taking over their row. Two columns, and both cases disappear.
 *
 * ⚠️ AND THE INDEX IS ON THE HASH, NOT ON THE ENDPOINT. FCM/Mozilla/Apple
 * endpoints run to hundreds of characters, well past MySQL's 3072-byte index
 * limit on utf8mb4 — an index on `text` is either refused outright or truncated
 * to a prefix that collides. `sha256` is fixed width, computed server-side, and
 * never accepted from the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'endpoint_hash']);

            // The module owns its own retention predicate, so it owns the index
            // that predicate reads (spec 013 · PersonalDataOwner::expire()).
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
