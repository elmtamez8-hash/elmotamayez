<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
| ٠٣٦ — «سجِّل الآن» في دعوةِ الدَّور، ونقلُ صفٍّ قائمٍ إلى النصِّ الجديد.
|
| ⛔ **النصُّ كانَ يأمرُ بفعلٍ يردُّه الباب.** الدعوةُ من الدَّورِ إسنادٌ لا بيعٌ
| (FR-020)، فتخرجُ إلى مجموعةٍ لم تُسعَّرْ باقتُها بعد — و`JoinCohort` يردُّ تلكَ
| المجموعةَ بـ`cohort_not_listed`. فالمدعوُّ يقرأُ «سجِّل الآن»، يُصطدَمُ برفضٍ،
| **وصفُّه مختومٌ بـ`invited_at` فلا يعودُ مرشَّحاً في جولةٍ بعدَها أبداً**.
|
| ⚠️ **وما يُقاسُ هنا هو الآليّةُ التي كُرِّرَت في هذا المستودعِ ولم تُقَسْ مرّةً:
| إعادةُ صياغةٍ مشروطة.** النقلُ غيرُ المشروطِ يمسحُ كلماتٍ ضبطَها المشغِّلُ من
| `/admin` على كلِّ نشر؛ والصياغةُ التي تُخالِفُ ما يكتبُه البذارُ تجعلُ قاعدةً
| قائمةً وقاعدةً جديدةً تحملانِ جملتَين لحدثٍ واحد.
*/
function waitlistInviteTemplate(): MessageTemplate
{
    $template = MessageTemplate::query()
        ->where('type', NotificationType::WaitlistInvited->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->first();

    expect($template)->toBeInstanceOf(MessageTemplate::class);

    return $template;
}

/** النصُّ الذي شحنَه ٠٣٤ — مكتوبٌ هنا حرفاً بحرفٍ كما تقرؤُه المايجريشن. */
const WAITLIST_SHIPPED_BODY = 'جاء دورك في «{{ course_title }}»: فُتح مكان في مجموعة «{{ cohort_name }}». سجِّل الآن — المقعد ليس محجوزاً لك، وهو لمن يسبق.';

function runWaitlistWordingMigration(): void
{
    $migration = require base_path(
        'app/Modules/Notifications/Database/Migrations/2026_09_19_000300_reword_waitlist_invited_for_a_group_awaiting_pricing.php'
    );

    $migration->up();
}

it('no longer tells an invited student to register on a group that may refuse them', function (): void {
    /*
    | ⚠️ **البذارُ هو المقياس، لا المايجريشن.** قاعدةٌ جديدةٌ لا تمرُّ من شرطِ
    | النقلِ إطلاقاً — تُولَدُ بالنصِّ الجديد — فتأكيدٌ على المايجريشن وحدَها
    | يتركُ البذارَ حرّاً في أن يُشحَنَ بالنصِّ القديم.
    */
    expect(waitlistInviteTemplate()->body)
        ->not->toContain('سجِّل الآن')
        ->toContain('افتحِ الكورس لتكمل')
        // ⚠️ والدَّورُ لا يحجزُ ولا يَعِدُ (FR-027) — الجملةُ تبقى.
        ->toContain('المقعد ليس محجوزاً لك');
});

it('carries an existing database forward to the very words the seeder writes', function (): void {
    $fresh = waitlistInviteTemplate()->body;

    // قاعدةٌ قائمةٌ منذُ ٠٣٤.
    waitlistInviteTemplate()->forceFill(['body' => WAITLIST_SHIPPED_BODY])->save();

    runWaitlistWordingMigration();

    /*
    | ⛔ **حرفاً بحرفٍ لا «تحتوي على».** لو افترقَت صياغةُ المايجريشن عن صياغةِ
    | البذارِ بحرفٍ واحد، حملَت قاعدةُ الإنتاجِ جملةً وحملَت كلُّ قاعدةٍ جديدةٍ
    | جملةً أخرى لحدثٍ واحد — وهي فرقةٌ لا يُحمِّرُها شيءٌ آخر.
    */
    expect(waitlistInviteTemplate()->body)->toBe($fresh);
});

it('leaves an operator their own words', function (): void {
    /*
    | ⚠️ كلُّ صفٍّ هنا قابلٌ للتحريرِ من `/admin`، ونقلٌ غيرُ مشروطٍ يمسحُ تحريرَ
    | المشغِّلِ على كلِّ نشر. والثمنُ مقصود: مَن حرَّرَ يبقى تحريرُه ويبقى معه
    | العطبُ — وهو أهونُ من استبدالِ كلماتِه بلا إذنِه.
    */
    $theirs = 'دورك جه في «{{ course_title }}» — مجموعة «{{ cohort_name }}». كلّمنا.';

    waitlistInviteTemplate()->forceFill(['body' => $theirs])->save();

    runWaitlistWordingMigration();

    expect(waitlistInviteTemplate()->body)->toBe($theirs);
});
