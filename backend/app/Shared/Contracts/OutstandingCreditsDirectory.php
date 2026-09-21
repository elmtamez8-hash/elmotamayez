<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * كم حصّةً مدفوعةً لم تُستهلَكْ بعدُ في ورشةٍ بعينِها — للجانبِ الذي لا يعرفُ الفوترة.
 *
 * ⛔ العدّادُ يصلُ إلى شاشةِ اعتمادِ السعرِ من هنا، لا باستيرادِ نموذجِ فوترة.
 *
 * `ContextIsolationTest` يمسحُ كلَّ ملفٍّ تحتَ `Modules/Settlement/` ويرفضُ فيه
 * `use App\Modules\Payments` **بأيِّ صورة** — وأسماءَ جداولِ الفوترةِ مقتبسةً كذلك.
 * فليست المسألةُ أن يبقى الرقمُ خارجَ حمولةِ التسوية: **تسميةُ السياقِ الآخرِ هي
 * المحظور**، حيثما وقعَت. وهذا عينُ ما وُضِعَ له `SettlementClearance` في الاتّجاهِ
 * المقابل: Compliance تسألُ Settlement ولا تستعلمُ جداولَها.
 *
 * ⚠️ أعدادٌ صِرفةٌ دخولاً وخروجاً، ولا نوعَ من Payments في التوقيعِ إطلاقاً — وهي
 * القاعدةُ التي يكتبُها {@see SessionCreditHolds} بنصِّها.
 *
 * ⚠️ والرقمانِ اثنانِ لأنّ الفرقَ بينَهما هو موضعُ القرار: `sold` حجمُ الدفترِ،
 * و`outstanding` ما لم يُسلَّمْ بعدُ — وهو وحدَه ما سيُسوّى بالسعرِ الجديد. الحصّةُ
 * المستهلَكةُ سُوِّيَت بالسعرِ الساري يومَ دُرِّسَت.
 */
interface OutstandingCreditsDirectory
{
    /**
     * @return array{sold: int, outstanding: int}
     */
    public function forWorkspace(int $workspaceId): array;
}
