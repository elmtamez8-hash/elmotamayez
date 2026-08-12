<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every notification a provider sent us — accepted, refused, or unresolvable.
 *
 * ⚠️ `workspace_id` IS NULLABLE, AND THE NULL IS THE HONEST ANSWER. The tenant is
 * derived from the referenced ORDER, and the two rows the nullability exists for
 * have no order to derive it from: a callback that arrived BEFORE the
 * transaction was written, and a callback whose signature failed — whose body
 * may not be parsed at all. A NOT NULL column leaves only two ways out and both
 * are forbidden: reading the tenant from a payload the other side wrote (not an
 * authorisation source — an unauthenticated attacker would plant rows in any
 * workspace they named), or writing `workspace_id = 0`, which hides the row from
 * the isolation test the constitution requires.
 *
 * ⚠️ `attempts` is unsignedSmallInteger, NOT TinyInteger. A limit above 255
 * overflows on MySQL and is accepted silently by SQLite — the same family as the
 * CAST(... AS SIGNED) trap in 006, and the same reason: the environment that
 * breaks is the one nobody runs the suite in.
 *
 * ⚠️ `unique(provider, external_id)` IS A FAST PATH, NOT THE GUARANTEE. It keys
 * on the RECEIPT, not the EFFECT: if the worker dies after the row is written
 * and before it is applied, nothing happened — and the provider's resend is then
 * refused as a "duplicate" while the charge never occurred. Worse, a gateway
 * that mints a fresh event id per resend (many do) makes the index prevent
 * nothing at all. The real guarantee is one captured transaction per order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_callbacks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            $table->string('provider');
            // Nullable: a refused callback gets NULL rather than a value read
            // from the attacker's body — see WebhookController.
            $table->string('external_id')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('result')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('payment_transaction_id');
            $table->index('workspace_id');
            // The deferred queue reads this: everything not yet applied.
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_callbacks');
    }
};
