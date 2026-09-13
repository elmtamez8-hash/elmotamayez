<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;

/**
 * A workspace opting into or out of the public marketplace (FR-001, FR-002).
 *
 * Withdrawing hides every teacher and course the workspace owns and touches no
 * teacher's approval_status. Withdrawal is the academy's decision about where its
 * listings appear, not a judgement on the people in them — and marking them
 * rejected would make re-joining a re-approval queue.
 *
 * ⚠️ **«لا حاجةَ لكتابةِ شيءٍ على المدرّسين» كانَ مكتوباً هنا، وهو غيرُ صحيح —
 * وثمنُه أنّ إعادةَ الانضمامِ لا تُعيدُ شيئاً.** `is_publicly_listed` عمودٌ
 * **مخزَّنٌ** مشتقٌّ من (معتمَد × المكانُ مشارِك)، ويكتبُه
 * {@see ApproveTeacherApplication} و{@see ReinstateTeacher} وحدَهما. فمدرّسٌ
 * اعتُمِدَ **بينما** مكانُه خارجَ السوقِ يُخزَّنُ عندَه `false`؛ ثمّ يعودُ المكانُ
 * إلى السوقِ ولا شيءَ يُعيدُ الحساب — فيبقى هو وكورساتُه مخفيّينِ إلى الأبد،
 * ولا مخرجَ إلّا اعتمادُ كلِّ مدرّسٍ من جديد.
 *
 * والالتباسُ مفهوم: استعلامُ **المدرّس** يسألُ عن المشاركةِ بوصلةٍ حيّةٍ فوقَ
 * العمودِ المخزَّن، فبدا العمودُ كأنّه بلا أثر. لكنّ الشرطَينِ مضروبانِ لا
 * مجموعان، فـ`false` مخزَّنةٌ تُلغي الوصلةَ الحيّةَ مهما قالت.
 *
 * ⚠️ والتحديثُ مقصورٌ على **المعتمَدين**: صفٌّ معلَّقٌ أو موقوفٌ يُرفَعُ إلى
 * `true` هنا يعني نشرَ مدرّسٍ لم يُعتمَدْ قطُّ بضغطةِ زرٍّ عن مكانِ العمل.
 *
 * ⚠️ وتحديثٌ جماعيٌّ لأنّ السؤالَ عن مكانِ عملٍ كاملٍ لا عن صفّ، والقيمةُ واحدةٌ
 * للجميع — والسحبُ يكتبُ `false` كذلك، فالعمودُ يبقى مساوياً لاشتقاقِه في
 * الاتّجاهَين. لا يعتمدُ شيءٌ على أحداثِ النموذجِ في هذا العمود.
 */
class SetMarketplaceParticipation extends Action
{
    public function handle(Workspace $workspace, bool $participates): Workspace
    {
        $workspace->forceFill(['participates_in_marketplace' => $participates])->save();

        TeacherProfile::query()
            // مكانُ عملٍ بعينِه يُطلَبُ صراحةً، والسياقُ قد يكونُ مكاناً آخرَ
            // تماماً — المشرِفُ العامُّ يرتدُّ إلى `last_workspace_id` مثلَ غيرِه.
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->getKey())
            ->where('approval_status', TeacherProfile::STATUS_APPROVED)
            ->update(['is_publicly_listed' => $participates]);

        MarketplaceCache::flush();

        return $workspace;
    }
}
