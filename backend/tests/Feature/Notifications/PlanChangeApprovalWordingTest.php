<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
| ٠٣٦ · FR-013 — «{{ hidden_cohorts }}» تصلُ قاعدةً قائمةً، لا القواعدَ الجديدةَ وحدَها.
|
| ⛔ **المدرّسُ كانَ لا يعرفُ شيئاً.** موافقةُ الموظَّفِ على طلبِ تعديلٍ قد
| تُسقِطُ مجموعةً فيها طلابٌ من كلِّ منتقٍ في المنتَج — والموظَّفُ يُحذَّرُ
| ويُعلِّمُ مربَّعاً ويمضي، بينما الرسالةُ التي تصلُ صاحبَ المجموعةِ تقولُ
| «وافقت الإدارة» ولا شيءَ غير.
|
| ⚠️ **وقاعدةُ الإنتاجِ لا تمرُّ من البذار.** صفُّ القالبِ مكتوبٌ منذُ شحنةٍ
| سابقة، و`seedMissing()` لا يلمسُ صفّاً موجوداً — فبلا مايجريشنٍ مشروطةٍ يبقى
| الإنتاجُ على النصِّ القديمِ إلى الأبد، ويقرأُ كلُّ مدرّسٍ رسالةً ناقصةً بينما
| كلُّ اختبارٍ أخضر. وهي الآليّةُ نفسُها التي دفعَ ثمنَها هذا المستودعُ خمسَ مرّاتٍ
| في الكتالوجات.
*/
function planApprovalTemplate(): MessageTemplate
{
    $template = MessageTemplate::query()
        ->where('type', NotificationType::PlanChangeApproved->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->first();

    expect($template)->toBeInstanceOf(MessageTemplate::class);

    return $template;
}

/** النصُّ الذي شحنَه ٠٣٦ — حرفاً بحرفٍ كما تقرؤُه المايجريشن. */
const PLAN_APPROVAL_SHIPPED_BODY = 'وافقت الإدارة على تعديل باقة «{{ plan_title }}». الباقة الجديدة تبيع {{ shape }}، والقديمة أُوقفت عن البيع ويبقى اشتراك من اشترك بها كما هو. {{ reason }}';

function runPlanApprovalWordingMigration(): void
{
    $migration = require base_path(
        'app/Modules/Notifications/Database/Migrations/2026_09_19_000800_tell_the_teacher_which_groups_an_approval_hid.php'
    );

    $migration->up();
}

it('asks for the hidden groups in the seeder, body and variable list alike', function (): void {
    /*
    | ⛔ **العمودانِ يتحرّكانِ معاً أو لا يتحرّك.** `variables` هي قائمةُ ما
    | يفحصُه `TemplateRenderer` قبلَ أن يعرض: متنٌ يحملُ `{{ hidden_cohorts }}`
    | وقائمةٌ لا تذكرُه تعني متغيّراً لا يُفحَصُ **ويُطبَعُ اسمُه حرفيّاً** في
    | رسالةٍ يقرؤها مدرّس؛ وقائمةٌ تذكرُه ومتنٌ بلا موضعٍ له تعني فحصاً لشيءٍ لا
    | يظهر.
    |
    | ⚠️ والبذارُ هو المقياس لا المايجريشن: قاعدةٌ جديدةٌ لا تمرُّ من شرطِ النقلِ
    | إطلاقاً، فتأكيدٌ على المايجريشن وحدَها يتركُ البذارَ حرّاً في أن يُشحَنَ
    | بالنصِّ القديم.
    */
    $template = planApprovalTemplate();

    expect($template->body)->toContain('{{ hidden_cohorts }}')
        ->and($template->variables)->toContain('hidden_cohorts');
});

it('carries an existing database forward to the very words the seeder writes', function (): void {
    $fresh = planApprovalTemplate();
    $freshBody = $fresh->body;
    $freshVariables = $fresh->variables;

    // قاعدةٌ قائمةٌ منذُ الشحنةِ السابقة.
    planApprovalTemplate()->forceFill([
        'body' => PLAN_APPROVAL_SHIPPED_BODY,
        'variables' => ['plan_title', 'shape', 'reason'],
    ])->save();

    runPlanApprovalWordingMigration();

    /*
    | ⛔ **حرفاً بحرفٍ لا «تحتوي على»**. لو افترقَت صياغةُ المايجريشن عن صياغةِ
    | البذارِ بحرفٍ واحد، حملَت قاعدةُ الإنتاجِ جملةً وحملَت كلُّ قاعدةٍ جديدةٍ
    | جملةً أخرى لحدثٍ واحد — وهي فرقةٌ لا يُحمِّرُها شيءٌ آخر.
    */
    expect(planApprovalTemplate()->body)->toBe($freshBody)
        ->and(planApprovalTemplate()->variables)->toBe($freshVariables);
});

it('leaves an operator their own words', function (): void {
    /*
    | ⚠️ كلُّ صفٍّ هنا قابلٌ للتحريرِ من `/admin`، ونقلٌ غيرُ مشروطٍ يمسحُ تحريرَ
    | المشغِّلِ على كلِّ نشر. والثمنُ مقصود: مَن حرَّرَ يبقى تحريرُه ويبقى معه
    | النقصُ — وهو أهونُ من استبدالِ كلماتِه بلا إذنِه.
    */
    $theirs = 'تمت الموافقة على «{{ plan_title }}» — {{ shape }}. {{ reason }}';

    planApprovalTemplate()->forceFill(['body' => $theirs])->save();

    runPlanApprovalWordingMigration();

    expect(planApprovalTemplate()->body)->toBe($theirs);
});
