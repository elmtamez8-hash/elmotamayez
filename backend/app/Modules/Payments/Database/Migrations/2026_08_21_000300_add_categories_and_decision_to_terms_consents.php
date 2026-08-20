<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns and a unique key on `terms_consents` (spec 013).
 *
 * ⚠️ THE MIGRATION IS IN `Payments` BECAUSE THE TABLE IS, and stating that plainly
 * matters: three earlier documents claimed spec 013 changed nothing in this
 * module. It changes four things — this migration, a reader, an adapter and two
 * arguments on the Action — and a claim of "zero changes" is how the fourth one
 * gets left out.
 *
 * ⚠️ AND `categories` IS A JSON COLUMN, NOT A CHILD TABLE. The set is read and
 * written together every time — no query ever asks "who consented to category X"
 * — so a child table makes every read a join and buys nothing.
 *
 * ⚠️ `null` AND `[]` ARE DIFFERENT ANSWERS. `null` means "this document has no
 * categories", which is every deferred-payment row already in the table. `[]`
 * means "they were asked, and consented to none of it". Defaulting the column to
 * an empty array would silently turn the first into the second for every historic
 * row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms_consents', function (Blueprint $table) {
            $table->json('categories')->nullable()->after('version');

            /*
            | ⚠️ WITHOUT THIS COLUMN "REFUSAL WINS" CANNOT BE EXPRESSED AT ALL.
            |
            | R6 decided that when two authorised guardians disagree, refusal
            | prevails — and the table records ACCEPTANCES ONLY. So with one
            | guardian consenting and another refusing, `hasCurrent()` finds the
            | consenting row and answers true: refusal does not win, the earlier
            | INSERT does. A rule written in prose with nowhere to store it is not
            | a rule.
            |
            | Defaulting to `granted` is what makes every existing row keep its
            | meaning. And a refusal is a ROW, never a delete — the same argument
            | the ledger makes for itself: this table is a record of decisions
            | taken at moments, and removing one destroys the evidence of what was
            | actually agreed.
            */
            $table->enum('decision', ['granted', 'refused'])->default('granted')->after('categories');
        });

        Schema::table('terms_consents', function (Blueprint $table) {
            /*
            | ⚠️ THE TABLE HAS ONLY ORDINARY INDEXES TODAY, so a double-submit
            | writes two rows — `ProcessingConsentGranted` fires twice, an account
            | is activated twice and a guardian is notified twice.
            |
            | `consented_at` IS PART OF THE KEY, and that is deliberate rather than
            | sloppy: withdrawing a category is a SECOND, legitimate row for the
            | same (student, document, version). What must not happen is two rows
            | at the same instant, which is exactly what a double-submit produces.
            */
            $table->unique(
                ['student_user_id', 'document', 'version', 'consented_at'],
                'terms_consents_decision_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('terms_consents', function (Blueprint $table) {
            $table->dropUnique('terms_consents_decision_unique');
            $table->dropColumn(['categories', 'decision']);
        });
    }
};
