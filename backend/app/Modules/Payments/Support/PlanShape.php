<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Shared\Support\CountedNoun;

/**
 * «١٢ حصّة» / «شهر واحد» — what a plan actually sells, in one sentence (٠٣٦ · US2).
 *
 * ⛔ ONE SPELLING FOR EVERY BACKEND SCREEN. The panel's plan table, the pricing
 * form and the approval queue each printed `duration_days.' يوماً'` directly, so
 * the day a plan could be sold by SESSIONS all three printed **«٠ يوماً»** — at
 * the officer deciding a teacher's price, about a plan with no duration at all.
 * Deriving «which shape is this» in each of them is the two-spellings defect this
 * tree has paid for a dozen times over.
 *
 * ⚠️ AND THE COUNT GOES THROUGH `CountedNoun`, NEVER A TEMPLATE LITERAL. Arabic
 * agrees the noun with its number across five CLDR bands, so «٢ حصص» is wrong
 * where «حصّتان» is right — and 12 and 30 are precisely the band where a literal
 * happens to agree, which is why the tests for this use neither.
 *
 * ⚠️ IT TAKES TWO NULLABLE INTEGERS RATHER THAN A `Plan`. Its other caller is the
 * ORDER SNAPSHOT, which is not a plan row and must not become one: a plan edited
 * while a bank transfer sits in review must not change what the buyer was sold.
 *
 * Returns `null` for a row carrying neither, so a caller joining parts with « · »
 * drops it rather than printing a dash inside a sentence; a table cell under a
 * «المدّة» header supplies its own placeholder.
 */
final class PlanShape
{
    public static function describe(?int $durationDays, ?int $sessionCount): ?string
    {
        if ($sessionCount !== null && $sessionCount > 0) {
            return CountedNoun::of($sessionCount, [
                'one' => 'حصّة واحدة',
                'two' => 'حصّتان',
                'few' => 'حصص',
                'many' => 'حصّة',
                'other' => 'حصّة',
            ]);
        }

        /*
        | ⛔ `> 0`, NOT `!== null`. A zero duration is a half-written row, and
        | «٠ يوماً» is the sentence that was already on the officer's screen —
        | the same zero that, written to a subscription, produces a window whose
        | end equals its start.
        */
        if ($durationDays !== null && $durationDays > 0) {
            /*
            | ⛔ **والشهورُ تُطوى كما تُطوى عندَ الطالبِ حرفاً بحرف (٠٣٦).**
            | الواجهةُ تقولُ «شهر واحد» عن ثلاثينَ يوماً منذُ ٠٣٤، وهذا كانَ
            | يقولُ «٣٠ يوماً» — **فالباقةُ الواحدةُ لها اسمانِ**: واحدٌ على
            | شاشةِ المشتري وآخرُ على شاشةِ الموظَّفِ الذي يُسعِّرُها وفي
            | الإشعارِ الذي يصلُ المدرّسَ باسمِه. ومدرّسٌ يقرأُ «٣٠ يوماً» ثمّ
            | يفتحُ صفحتَه فيجدُ «شهر واحد» يظنُّهما صفَّين.
            |
            | ⚠️ **والقسمةُ على ٣٠ لا على تقويم.** «شهر» هنا مدّةُ اشتراكٍ لا
            | شهرٌ ميلاديّ، و`planDuration()` في `lib/plans.ts` تقسِمُ كذلك —
            | فطيٌّ بحسابِ الأشهرِ الحقيقيّةِ إملاءٌ ثانٍ يفترقُ عندَ ٢٨ و٣١.
            |
            | ⚠️ **والفرعانِ يمرّانِ من `CountedNoun`**، والواجهةُ من `counted()`
            | بالصيغِ نفسِها حرفاً بحرف. «٧ يوماً» و«3 أشهر» كانتا مكتوبتَينِ
            | بقالبٍ نصّيٍّ على الجهتَين — وهو ما تمنعُه قاعدةُ العددِ المعدودِ
            | في هذا المستودعِ منذُ «٢ مدرّس متاح».
            */
            if ($durationDays % 30 === 0) {
                return CountedNoun::of(intdiv($durationDays, 30), [
                    'one' => 'شهر واحد',
                    'two' => 'شهران',
                    'few' => 'أشهر',
                    'many' => 'شهراً',
                    'other' => 'شهر',
                ]);
            }

            return CountedNoun::of($durationDays, [
                'one' => 'يوم واحد',
                'two' => 'يومان',
                'few' => 'أيّام',
                'many' => 'يوماً',
                'other' => 'يوم',
            ]);
        }

        return null;
    }
}
