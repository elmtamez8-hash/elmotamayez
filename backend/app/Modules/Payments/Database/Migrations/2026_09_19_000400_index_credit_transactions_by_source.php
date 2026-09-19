<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ٠٣٦ — «ما مصيرُ هذه البيعة؟» مسؤولةٌ من المصدرِ لا من الرصيد.
 *
 * ⛔ **`credit_tx_idempotency` يبدأُ بالرصيد، وهناكَ قارئانِ لا يملكانِه.**
 * الفهرسُ القائمُ `(credit_balance_id, type, source_type, source_id)`، وعمودُه
 * الأوّلُ هو ما يجعلُه صالحاً — فسؤالٌ بـ`(source_type, source_id)` وحدَهما لا
 * يستطيعُ استعمالَه إطلاقاً ويمسحُ الجدول. وبيعةُ الحصصِ لا تكتبُ صفَّ
 * `credit_purchases` أصلاً، فلا شيءَ يُقرَأُ منه الرصيدُ قبلَ السؤال.
 *
 * والقارئانِ هما مدقّقُ سلسلةِ الدفعةِ الواحدة (`PaymentAuditController::show`)
 * والمطابقةُ الليليّة (`ReconcilePayments`) — والثانيةُ تمشي على أسرعِ جدولَينِ
 * نموّاً في المنظومة، فمسحٌ هناك يكبرُ مع كلِّ بيعةٍ إلى الأبد.
 *
 * ⚠️ **والاسمُ مكتوبٌ بيدٍ** — MySQL يرفضُ أيَّ معرِّفٍ يتجاوزُ ٦٤ محرفاً
 * وSQLite لا حدَّ عندَه، فالاسمُ المولَّدُ يمرُّ من كلِّ شريحةٍ في CI ثمّ يُوقِفُ
 * النشرَ على الإنتاج، ويُسقِطُ معه كلَّ مايجريشنٍ خلفَه. (المولَّدُ هنا ٤٩
 * محرفاً — الاسمُ باليدِ قاعدةٌ لا إنقاذُ حالة.)
 *
 * ⚠️ **و`hasIndex()` هو الحارس**: المايجريشنُ قد يُعادُ على قاعدةٍ أُضيفَ إليها
 * الفهرسُ بيدٍ بعدَ حادثةِ `course_waitlist_entries`.
 */
return new class extends Migration
{
    private const INDEX = 'credit_transactions_source_idx';

    public function up(): void
    {
        if (Schema::hasIndex('credit_transactions', self::INDEX)) {
            return;
        }

        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->index(['source_type', 'source_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('credit_transactions', self::INDEX)) {
            return;
        }

        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
