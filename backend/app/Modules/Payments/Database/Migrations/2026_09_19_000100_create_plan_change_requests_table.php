<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ٠٣٦ — المدرّسُ يطلبُ تعديلَ باقةٍ سعَّرَتها المنصّة (قرارُ المالك ٢٠٢٦-٠٩-١٩).
 *
 * ⛔ **هذا الجدولُ هو المخرجُ من رفضٍ، لا ميزةٌ مستقلّة.** `SavePlan` يرفضُ
 * تحريكَ شكلِ باقةٍ مسعَّرةٍ أو تغطيتِها لأنّ ذلك يُحرِّكُ ما سُعِّرَ من تحتِ
 * سعرِه؛ ورفضٌ بلا مخرجٍ يعني مدرّساً يفتحُ تذكرةَ دعمٍ في كلِّ مرّة، أو —
 * أسوأ — يُنشئُ باقةً جديدةً ويتركُ القديمةَ معروضة.
 *
 * ⚠️ **والقيمتانِ تُحفَظانِ معاً: ما كانَ وما طُلِب.** قرارٌ يُقرَأُ بعدَ سنةٍ
 * لا يُعادُ بناؤُه من صفِّ الباقةِ الحيِّ — فالباقةُ تكونُ قد تحرّكَت مرّاتٍ
 * بعدَه، والسؤالُ «على أيِّ شيءٍ وافقَ الموظَّفُ يومَها» يصيرُ بلا جواب. هذه
 * تهجئةُ `rate_change_requests` نفسُها في ٠٠٦، وللسببِ نفسِه بنصِّه.
 *
 * ⚠️ **والسعرُ المطلوبُ قابلٌ للفراغ، وفراغُه ليسَ صفراً.** المدرّسُ قد يطلبُ
 * شكلاً جديداً ويتركُ الثمنَ للمنصّةِ كما هي القاعدةُ أصلاً (FR-025)، وصفرٌ
 * هناك يُقرَأُ «مجّاناً» وهو ما لا يقصدُه أحد.
 *
 * ⚠️ **و`approved_plan_id` يُكتَبُ عندَ الموافقة، لأنّ الموافقةَ تكتبُ باقةً
 * جديدةً وتوقفُ القديمةَ** (قرارُ المالك): الاشتراكاتُ القائمةُ تُسمّي الباقةَ
 * التي اشتُرِيَت، فتعديلُ الصفِّ مكانَه يُغيِّرُ ما يقرؤُه مشترٍ عن شيءٍ اشتراه
 * بشروطٍ أخرى. والعمودُ هو الخيطُ الوحيدُ بينَ القديمةِ والجديدةِ بعدَها.
 *
 * ⚠️ **والاسمُ مكتوبٌ بيدٍ** — عُرفُ ما بعدَ ٠٣٤: الشكلُ التلقائيُّ قد يتجاوزُ
 * ٦٤ محرفاً، وMySQL يرفضُ و SQLite لا حدَّ عندَه، فيمرُّ في الشرائحِ الأربعِ
 * ويسقطُ في أوّلِ نشرٍ ويأخذُ معه كلَّ ترحيلٍ خلفَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('plan_id');

            // ما كانَ عليه الصفُّ لحظةَ الطلب.
            $table->unsignedInteger('current_duration_days')->nullable();
            $table->unsignedInteger('current_session_count')->nullable();
            $table->string('current_session_type', 16);
            $table->string('current_coverage_type', 16);
            $table->uuid('current_coverage_uuid')->nullable();
            $table->unsignedBigInteger('current_price_minor')->nullable();

            // وما طلبَه المدرّس.
            $table->unsignedInteger('requested_duration_days')->nullable();
            $table->unsignedInteger('requested_session_count')->nullable();
            $table->string('requested_session_type', 16);
            $table->string('requested_coverage_type', 16);
            $table->uuid('requested_coverage_uuid')->nullable();
            $table->unsignedBigInteger('requested_price_minor')->nullable();

            $table->text('reason')->nullable();

            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();

            // الباقةُ التي كتبَتها الموافقة؛ فارغةٌ لكلِّ طلبٍ لم يُوافَقْ عليه.
            $table->unsignedBigInteger('approved_plan_id')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'plan_change_requests_workspace_status_idx');
            $table->index(['plan_id', 'status'], 'plan_change_requests_plan_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_requests');
    }
};
