"use client";

import { useAuth } from "@/lib/auth-context";
import { can, P } from "@/lib/permissions";
import { privateSessions } from "@/lib/private-sessions";
import { SessionsIcon } from "@/components/icons";
import { CountCard } from "./CountCard";

/**
 * طلباتُ الحصصِ الخاصّةِ الواردةُ التي لم يُبَتَّ فيها (٠٢٩ · `FR-007`).
 *
 * ⚠️ **بلا `per_page=1`.** الطابورُ `paginate(20)` بعددٍ ثابتٍ لا يقرأُ ذلك
 * المعامِلَ أصلاً، ومعامِلٌ في العنوانِ لا يفعلُ شيئاً ادّعاءُ عقدٍ يقرؤُه القارئُ
 * التالي على أنّه صحيح. و`meta.total` صادقٌ مهما كانَ حجمُ الصفحة.
 *
 * ⚠️ ولا `status=pending` كذلك: الطابورُ **افتراضُه** المنتظِرُ (`->pending()` في
 * الفرعِ الآخرِ من `when`)، فإرسالُها تهجئةٌ ثانيةٌ لشرطٍ يملكُه الخادم.
 */
const countWaiting = () => privateSessions.queue().then((page) => page.meta?.total ?? 0);

export function PrivateRequestsCard() {
  const { user } = useAuth();

  return (
    <CountCard
      title="طلبات الحصص الخاصة"
      Icon={SessionsIcon}
      href="/manage/private-sessions"
      linkLabel="الطلبات"
      label="طلب ينتظر ردّك"
      granted={can(user, P.sessionsManage)}
      empty="لا طلبات تنتظر ردّك."
      load={countWaiting}
    />
  );
}
