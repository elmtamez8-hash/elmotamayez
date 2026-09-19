<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `credit_purchases.order_id` تحملُ فهرسَينِ على عمودٍ واحد.
 *
 * ⛔ **والسببُ وثيقةٌ كاذبة، لا سهو.** `_2026_09_09_000100_index_credit_purchases_by_order`
 * تقولُ في صدرِها «`credit_purchases.order_id` had no index at all» و«the table
 * carried exactly two indexes» — **والجملتانِ خطأ**: `_2026_08_11_001000_add_reporting_indexes`
 * أضافَ `$table->index('order_id')` قبلَها بشهر، وتعليقُه يُسمّي العمودَ
 * «مفصلَ سلسلةِ FR-028». فكاتبُ الثانيةِ قرأَ الجدولَ ولم يقرأْ ما سبقَه، وكتبَ
 * الغيابَ حقيقةً. **وهذا بالضبطِ نوعُ الادّعاءِ الذي تقولُ قاعدةُ هذا المستودعِ
 * إنّه يُفحَصُ قبلَ أن يُكتَب: ادّعاءُ الغيابِ وادّعاءُ الحصر.**
 *
 * وقِيسَ على قاعدةٍ حقيقيّةٍ في ٢٠٢٦-٠٩-١٩: الصفّانِ قائمانِ معاً،
 * `credit_purchases_order_id_unique` و`credit_purchases_order_id_index`.
 *
 * ⚠️ **والزائدُ هو العاديُّ لا الفريد.** الفريدُ يخدمُ كلَّ ما يخدمُه العاديُّ
 * ويزيد — `Order::creditPurchase()` عَلاقةُ `HasOne`، فصفٌّ ثانٍ لطلبٍ واحدٍ
 * عطبٌ لا يستطيعُ التطبيقُ التعبيرَ عنه، والفهرسُ الفريدُ يقولُ ذلكَ للمحرّكِ
 * أيضاً ويحوّلُ القراءةَ إلى `eq_ref`. والعاديُّ شجرةٌ ثانيةٌ تُكتَبُ مع كلِّ
 * بيعةٍ على المنصّةِ ولا تُقرَأُ أبداً.
 *
 * ⚠️ **ومشروطٌ بـ`hasIndex()`**: قاعدةٌ وُلِدَت بعدَ التصحيحِ لا تحملُه، ومحاولةُ
 * إسقاطِ فهرسٍ غائبٍ تُوقِفُ النشرةَ وتُسقِطُ معها كلَّ مايجريشنٍ خلفَها — وهي
 * حادثةُ `course_waitlist_entries` نفسُها من بابٍ آخر.
 *
 * ولا يُكتَبُ صفٌّ ولا يُعدَّل.
 */
return new class extends Migration
{
    private const INDEX = 'credit_purchases_order_id_index';

    public function up(): void
    {
        if (! Schema::hasIndex('credit_purchases', self::INDEX)) {
            return;
        }

        Schema::table('credit_purchases', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /**
     * ⚠️ **تُعيدُ الزائدَ عمداً.** التراجعُ يُعيدُ الحالَ كما كانت، ولو كانت
     * الحالُ نفسُها هي العطب — فمايجريشنٌ لا تعكسُ نفسَها تترُكُ القاعدةَ في
     * شكلٍ لا يصفُه أيُّ صفٍّ في `migrations`.
     */
    public function down(): void
    {
        if (Schema::hasIndex('credit_purchases', self::INDEX)) {
            return;
        }

        Schema::table('credit_purchases', function (Blueprint $table): void {
            $table->index('order_id', self::INDEX);
        });
    }
};
