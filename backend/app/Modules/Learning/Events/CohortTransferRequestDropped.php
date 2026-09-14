<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use App\Modules\Learning\Models\CohortTransferRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * طلبُ انتقالٍ معلَّقٌ فقدَ محلَّه (٠٣٤ · FR-008).
 *
 * ⚠️ **ليسَ رفضاً، ولذلكَ ليسَ `CohortTransferDecided`.** الرفضُ قرارٌ اتُّخِذَ في
 * الطلبِ نفسِه ويحملُ سببَ مَن رفض؛ وهذا أنّ حركةً أخرى — إسنادٌ إداريّ، أو نقلٌ
 * يدويّ، أو أرشفةُ المجموعةِ المقصودة — جعلَت الطلبَ بلا معنى. الطالبُ يقرأُ
 * السببَ في الحالتَين، والجملتانِ مختلفتانِ تماماً.
 *
 * ⚠️ **والسببُ كانَ يُكتَبُ ولا يقرؤُه الطالبُ أبداً.** `PendingTransfer::drop()`
 * يحفظُه في `decision_reason` منذُ ٠٢١، وقارئا ذلكَ العمودِ مساران إداريّانِ
 * للمدرّسِ وحدَه — فالجملةُ موجودةٌ، والطلبُ يختفي من شاشةِ الطالبِ بلا كلمة،
 * ويُعادُ إرسالُه (٠٢١ · FR-028ح).
 *
 * يحملُ النموذجَ لا المعرِّفات: القارئُ الوحيدُ في `Notifications`، وهي وحدةٌ
 * تقرأُ نماذجَ الوحداتِ الأخرى بالتصميمِ — بخلافِ `CohortMembershipOpened`، الذي
 * يعبرُ إلى `LiveSessions`.
 */
class CohortTransferRequestDropped
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CohortTransferRequest $request,
        public readonly string $reason,
        /** مَن أسقطَه — و`null` حينَ لا فاعلَ إنسانيّاً (كنسٌ أو بذرة). */
        public readonly ?int $actorUserId,
    ) {}
}
