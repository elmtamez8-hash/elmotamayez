<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٦ · FR-013 — المدرّسُ يعرفُ أيَّ مجموعاتِه خرجَت من العرض.
 *
 * ⛔ **الموظَّفُ يُحذَّرُ ويُقرِّر، والمدرّسُ كانَ لا يعرفُ شيئاً — لا قبلَ ولا
 * بعد.** الموافقةُ على طلبِ تعديلٍ هي الكتابةُ التي تُضيِّقُ تغطيةَ باقةٍ
 * سعّرَتها المنصّة، فقد تُسقِطُ مجموعةً فيها طلابٌ من كلِّ منتقٍ في المنتَج.
 * و`DecidePlanChange` صارَ يرفضُ ذلك بالأسماءِ حتّى يُعلِّمَ الموظَّفُ مربَّعاً —
 * لكنّ تعليمَ المربَّعِ قبولٌ للكلفةِ لا إذنٌ بكتمانِها عن صاحبِ المجموعات.
 * وقبلَ هذا السطرِ كانَ المدرّسُ يقرأُ «وافقت الإدارة… الباقة الجديدة تبيع ٣٠
 * يوماً» ولا شيءَ غير، ثمّ يكتشفُ الأمرَ حينَ يسألُه طالبٌ لماذا لا تظهرُ
 * مجموعتُهم.
 *
 * ⚠️ **والعددُ هنا مقيسٌ لا متنبَّأٌ به**، وهذا سببُ كونِه في الموافقةِ لا في
 * الطلب: لحظةَ يبعثُ المدرّسُ طلبَه لا يُكتَبُ شيءٌ إطلاقاً، فأيُّ رقمٍ هناكَ
 * تخمينٌ على صفٍّ غيرِ موجودٍ وعلى قرارٍ يبعدُ أيّاماً.
 *
 * ⚠️ **والمتغيّرُ لا يكونُ فارغاً أبداً.** `TemplateRenderer` يعدُّ المتغيّرَ
 * الفارغَ **غائباً** ويرمي فشلَ تسليمٍ دائماً، فموافقةٌ لم تُخفِ شيئاً كانت
 * ستُسقِطُ الرسالةَ كلَّها — والمدرّسُ الذي لم تكلّفْه الموافقةُ شيئاً لا يُبلَّغُ
 * بالموافقةِ نفسِها. `DecidePlanChange::hiddenSentence()` يردُّ جملةً هادئةً في
 * تلكَ الحالة، تماماً كما يفعلُ `reason` جنبَها.
 *
 * ⚠️ **ومشروطةٌ بأنّ النصَّ ما زالَ نصَّه المشحون حرفاً بحرف**، فتعديلُ مسؤولٍ
 * من `/admin` لا يُداس. ومكتوبةٌ بالنموذجِ لا بـ`DB::table()`: العمودُ مترجَمٌ
 * منذُ ٠٥٥ فكتابةٌ خامٌّ فيه تُقرَأُ فراغاً. انظر سابقتَها
 * `_000300_reword_waitlist_invited_for_a_group_awaiting_pricing`.
 *
 * ⚠️ **والعمودُ `variables` يتحرَّكُ مع المتن، لا بعدَه.** هو قائمةُ ما يطلبُه
 * العارضُ قبلَ أن يعرض، فمتنٌ يحملُ `{{ hidden_cohorts }}` وقائمةٌ لا تذكرُه
 * تعني متغيّراً لا يُفحَصُ ويُطبَعُ اسمُه حرفيّاً في رسالةٍ يقرؤها مدرّس.
 */
return new class extends Migration
{
    /** نصُّ ٠٣٦ المشحون حرفاً بحرف — الشرطُ الذي تُكتَبُ عندَه وحدَه. */
    private const SHIPPED_BODY = 'وافقت الإدارة على تعديل باقة «{{ plan_title }}». الباقة الجديدة تبيع {{ shape }}، والقديمة أُوقفت عن البيع ويبقى اشتراك من اشترك بها كما هو. {{ reason }}';

    private const REWORDED_BODY = 'وافقت الإدارة على تعديل باقة «{{ plan_title }}». الباقة الجديدة تبيع {{ shape }}، والقديمة أُوقفت عن البيع ويبقى اشتراك من اشترك بها كما هو. {{ hidden_cohorts }} {{ reason }}';

    /** @var list<string> */
    private const REWORDED_VARIABLES = ['plan_title', 'shape', 'hidden_cohorts', 'reason'];

    public function up(): void
    {
        $template = MessageTemplate::query()
            ->where('type', NotificationType::PlanChangeApproved->value)
            ->where('channel', NotificationChannel::InApp->value)
            ->first();

        if (! $template instanceof MessageTemplate || $template->body !== self::SHIPPED_BODY) {
            return;
        }

        $template->forceFill([
            'body' => self::REWORDED_BODY,
            'variables' => self::REWORDED_VARIABLES,
        ])->save();
    }

    /** فارغةٌ عمداً — انظر سابقاتِها. */
    public function down(): void {}
};
