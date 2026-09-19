<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جسرُ الباقاتِ يسألُ `(workspace_id, coverage_uuid)` ولا فهرسَ له (٠٣٦).
 *
 * ⛔ **و`plans` كانت تحملُ فهرساً واحداً غيرَ المفتاح: `(workspace_id,
 * is_active)`** — قِيسَ على قاعدةٍ حقيقيّةٍ في ٢٠٢٦-٠٩-١٩. و`PlanReach::naming()`
 * هي `whereIn('workspace_id') + whereIn('coverage_uuid')` حرفيّاً، فلا يخدمُها
 * ذلكَ الفهرسُ في شيء: العمودُ الثاني ليسَ فيه.
 *
 * ⚠️ **والقارئانِ ليسا شاشةَ إدارةٍ يفتحُها واحدٌ في الشهر.** هذا السؤالُ هو
 * بوّابةُ ٠٣٦ نفسُها: يُسأَلُ في **كلِّ صفحةِ كورسٍ عامّةٍ** وفي كلِّ قائمةِ
 * مجموعاتٍ يفتحُها طالب، وعندَ كلِّ محاولةِ شراء. فمسحٌ هنا يكبرُ مع كلِّ باقةٍ
 * تُكتَبُ على المنصّةِ إلى الأبد.
 *
 * ⚠️ **والعمودانِ بهذا الترتيب.** `workspace_id` أوّلاً لأنّه شرطُ المساواةِ
 * الذي يقصُّ الجدولَ إلى مدرّسٍ واحد، و`coverage_uuid` بعدَه — وهو ما يجعلُ
 * القراءةَ من الفهرسِ وحدَه: `naming()` لا تطلبُ غيرَ هذَين والمفتاحِ الأساسيِّ،
 * وهو مضمَّنٌ في كلِّ فهرسٍ ثانويٍّ على InnoDB.
 *
 * ⚠️ **والاسمُ مكتوبٌ بيدٍ** — MySQL يرفضُ أيَّ معرِّفٍ يتجاوزُ ٦٤ محرفاً وSQLite
 * لا حدَّ عندَه، فالاسمُ المولَّدُ يمرُّ من كلِّ شريحةٍ في CI ثمّ يُوقِفُ النشرَ
 * على الإنتاجِ ويُسقِطُ معه كلَّ مايجريشنٍ خلفَه.
 *
 * ولا يُكتَبُ صفٌّ ولا يُعدَّل.
 */
return new class extends Migration
{
    private const INDEX = 'plans_workspace_coverage_uuid_idx';

    public function up(): void
    {
        if (Schema::hasIndex('plans', self::INDEX)) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->index(['workspace_id', 'coverage_uuid'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('plans', self::INDEX)) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
