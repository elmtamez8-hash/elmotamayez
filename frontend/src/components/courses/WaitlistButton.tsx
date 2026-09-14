"use client";

import { useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { hasAuthToken } from "@/lib/api";
import { cohorts } from "@/lib/cohorts";
import { userMessage } from "@/lib/errors";

/**
 * «سجّلني في الدَّور» على كورسٍ اكتملت مجموعاتُه (٠٣٤ · FR-026 · FR-027).
 *
 * ⚠️ **والجملةُ تقولُ إنّ الدَّورَ لا يحجز، قبلَ الضغطِ وبعدَه.** دَورٌ يُقرَأُ
 * وعداً هو وعدٌ يُخلَف: مَن يُدعى يسجّلُ كأيِّ طالبٍ آخر، ومَن يتأخّرُ يفوتُه
 * المكان. وإخفاءُ ذلكَ حتّى تصلَ الدعوةُ يجعلُ الخُلْفَ مفاجأة.
 *
 * ⚠️ **ولا رقمَ موضعٍ يُعرَض.** الخادمُ لا يُرسِلُه أصلاً، ورقمٌ على الشاشةِ
 * يُقرَأُ حجزاً مهما قالَ النصُّ حولَه — فالرقمُ للإدارةِ وحدَها، حيثُ يُقرَأُ
 * ترتيباً لا وعداً.
 *
 * ⚠️ **وزائرٌ بلا حساب يُدعى إلى الدخولِ لا يُضغَطُ به الزرّ.** الصفحةُ عامّةٌ
 * ومرسومةٌ على الخادم، فالضغطُ بلا رمزٍ ينتهي بـ401 يُحوِّلُه معالِجُ الأخطاءِ
 * إلى «انتهت جلستُك» — جملةٌ كاذبةٌ لمن لم تبدأْ له جلسةٌ قطّ.
 *
 * ⚠️ **والرمزُ يُقرَأُ في `useEffect` لا في التصيير**، كما تفعلُ جارتُه
 * `MyCohort` على الصفحةِ نفسِها. `getToken()` يردُّ `null` على الخادمِ دائماً،
 * فقراءتُه في التصييرِ ترسمُ «سجّلْ دخولك» على الخادمِ والزرَّ في المتصفّح —
 * اختلافُ ترطيبٍ لا يراهُ jsdom ويراهُ كلُّ زائرٍ مسجَّلٍ في طرَفيّتِه.
 */
export function WaitlistButton({ courseUuid }: { courseUuid: string }) {
  const [state, setState] = useState<"idle" | "pending" | "done">("idle");
  const [error, setError] = useState<string | null>(null);
  // `null` حتّى يعملَ المتصفّح: الخادمُ لا يعرفُ ولا يدّعي.
  const [signedIn, setSignedIn] = useState<boolean | null>(null);

  useEffect(() => {
    setSignedIn(hasAuthToken());
  }, []);

  if (signedIn === null) {
    // ⚠️ لا شيءَ في التصييرِ الأوّل — لا زرٌّ ولا دعوةٌ إلى الدخول. أيُّهما
    // رُسِمَ هنا صارَ نصفَه خطأً في المتصفّحِ بعدَ لحظة.
    return null;
  }

  if (!signedIn) {
    return (
      <p className="text-sm text-ink-muted">
        <Link href="/login" className="font-bold text-primary-ink underline">
          سجّل دخولك
        </Link>{" "}
        لتسجّل في الدَّور — ونُعلِمك حين يُفتح مكان.
      </p>
    );
  }

  if (state === "done") {
    return (
      <Alert tone="success" title="سجّلناك في الدَّور">
        سنُعلِمك حين يُفتح مكان. والمقعد ليس محجوزاً لك — من يُدعى يسجّل، ومن يتأخّر يفوته.
      </Alert>
    );
  }

  const join = () => {
    setState("pending");
    setError(null);

    cohorts
      .joinWaitlist(courseUuid)
      .then(() => setState("done"))
      .catch((e: unknown) => {
        setError(userMessage(e));
        setState("idle");
      });
  };

  return (
    <div className="flex flex-col gap-2">
      {error !== null && (
        <Alert tone="danger" title="تعذّر التسجيل في الدَّور">
          {error}
        </Alert>
      )}

      <Button onClick={join} loading={state === "pending"} loadingLabel="جارٍ التسجيل" size="sm">
        سجّلني في الدَّور
      </Button>
    </div>
  );
}
