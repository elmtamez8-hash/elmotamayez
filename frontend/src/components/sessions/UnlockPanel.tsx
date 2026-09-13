"use client";

import { useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { userMessage } from "@/lib/errors";
import { classSessions, type SessionContentOffer } from "@/lib/class-sessions";
import { counted, formatDateTime } from "@/lib/labels";

/**
 * ٠٣٥ · FR-009 · FR-013 — «هذه الحصّةُ مقفولة. افتحْها بخصمِ حصّةٍ من رصيدِك؟»
 *
 * ⛔ زرٌّ بلا رقمٍ ليسَ اختياراً. قرارُ المالكِ أنّ الطالبَ يقرّرُ **بحرّيّة**
 * يشترطُ أن يعرفَ الثمنَ قبلَ الضغط، فالثلاثةُ تُعرَضُ معاً: كم سيُخصَم · كم
 * يملكُ الآن · ما الذي سيُفتَح.
 *
 * ⛔ **وطريقُ الشراءِ حاضرٌ دائماً، لا حينَ يعجِزُ الرصيدُ وحدَه.** حمولةٌ — أو
 * شاشةٌ — يتغيّرُ شكلُها بما وجدَتْه جوابٌ مُميِّز، والأهمُّ أنّ الرفضَ بلا مخرجٍ
 * هو الشكلُ الذي تمنعُه FR-013 نصّاً. ولا زرَّ معطَّلاً: زرٌّ رماديٌّ بلا جملةٍ
 * هو «غير مسموح» يرتدي وجهاً ألطف.
 *
 * ⚠️ **وما سيُفتَحُ أعدادٌ وأصنافٌ لا عناوين.** قائمةُ عناوينِ أوراقِ ساعةٍ هي
 * خطّةُ درسٍ لمن لم يحضرْها — نفسُ الخطِّ الذي ترسمُه قائمةُ الحقولِ العامّةِ على
 * معرِّفِ الدرس، وبالسببِ نفسِه.
 *
 * ⚠️ **ولا مبلغَ نقديٍّ في أيِّ سطر.** ثمنُ الحصّةِ هو أجرُ المدرّسِ المعتمَدُ
 * مضافاً إليه ثابتانِ للمنصّة، فرقمٌ يُعرَضُ على الطالبِ يُحَلُّ لأجرِ المدرّسِ
 * عبرَ حجمَي باقةٍ (FR-021ج).
 */

const KIND_LABELS: Record<string, (count: number) => string> = {
  lesson: (count) =>
    counted(count, {
      one: "درس",
      two: "درسان",
      few: "دروس",
      many: "درساً",
      other: "درس",
    }),
  assignment: (count) =>
    counted(count, {
      one: "واجب",
      two: "واجبان",
      few: "واجبات",
      many: "واجباً",
      // ⚠️ `other` مطلوبٌ ولا يرثُ `one`: «واحد» مفردٌ قائمٌ بذاتِه لا يتبعُ
      // عدداً، و«١٠٠ واجب واحد» أسوأُ ممّا كانَ قبلَه.
      other: "واجب",
    }),
  exam: (count) =>
    counted(count, {
      one: "امتحان",
      two: "امتحانان",
      few: "امتحانات",
      many: "امتحاناً",
      other: "امتحان",
    }),
};

function describeOpens(opens: Record<string, number>): string[] {
  return Object.entries(opens)
    .filter(([, count]) => count > 0)
    .map(([kind, count]) => KIND_LABELS[kind]?.(count) ?? `${count} ${kind}`);
}

export function UnlockPanel({
  sessionUuid,
  offer,
  onOpened,
}: {
  sessionUuid: string;
  offer: SessionContentOffer;
  onOpened?: () => void;
}) {
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  const affordable = offer.available_credits >= offer.credits;
  const opens = describeOpens(offer.opens);

  async function open() {
    setPending(true);
    setFailure(null);

    try {
      await classSessions.unlock(sessionUuid);
      onOpened?.();
    } catch (error) {
      // ⚠️ لا خطأٌ خام. الخادمُ يُجيبُ ٤٠٩ «مفتوحٌ لك بالفعل» و٤٢٢ «لا يكفي»
      // و٤٠٣ «ليست لك» — ثلاثُ جملٍ مختلفة، و`userMessage()` هي التي تُحوِّلُ
      // أيَّها إلى نصٍّ يُقرَأ.
      setFailure(userMessage(error));
    } finally {
      setPending(false);
    }
  }

  return (
    <Card as="section" padding="md">
      <h2 className="text-base font-semibold text-ink">محتوى هذه الحصّة مقفول</h2>

      <p className="mt-2 text-sm text-ink-muted">
        لم تحضرِ البثَّ الحيَّ لهذه الحصّة، فلم تُخصَمْ منكَ. يمكنكَ فتحُ محتواها
        كلِّه بخصمِ{" "}
        <strong className="text-ink">
          {counted(offer.credits, {
            one: "حصة واحدة",
            two: "حصتين",
            few: "حصص",
            many: "حصة",
            other: "حصة",
          })}
        </strong>{" "}
        من رصيدِك.
      </p>

      {opens.length > 0 && (
        <p className="mt-3 text-sm text-ink-muted">
          سيُفتَحُ لك: <span className="text-ink">{opens.join(" · ")}</span>
        </p>
      )}

      <p className="mt-1 text-sm text-ink-muted">
        رصيدُكَ المتاحُ الآن:{" "}
        <strong className="text-ink">
          {counted(offer.available_credits, {
            one: "حصة واحدة",
            two: "حصتان",
            few: "حصص",
            many: "حصة",
            other: "حصة",
            zero: "لا رصيد",
          })}
        </strong>
        {offer.owned_credits !== offer.available_credits && (
          <>
            {" "}
            <span className="text-xs">
              (
              {counted(offer.owned_credits - offer.available_credits, {
                one: "حصة واحدة محجوزة",
                two: "حصتان محجوزتان",
                few: "حصص محجوزة",
                many: "حصة محجوزة",
                other: "حصة محجوزة",
              })}{" "}
              لحصصٍ أخرى)
            </span>
          </>
        )}
      </p>

      {offer.available_until !== null && (
        /*
         * FR-039ج — المادّةُ لها أجلٌ والفتحُ لا يمدُّه. ثمنُ بقاءِ سياسةِ
         * الاحتفاظِ دونَ مساسٍ هو أن يُقالَ للطالبِ **قبلَ** أن يضغط: فتحٌ
         * لأسبوعٍ باقٍ ثمنُه حصّةٌ كاملةٌ قرارٌ مختلفٌ عن فتحٍ لسنة.
         */
        <p className="mt-1 text-xs text-ink-muted">
          المادّةُ متاحةٌ حتى {formatDateTime(offer.available_until)} — والفتحُ لا
          يمدِّدُ هذه المدّة.
        </p>
      )}

      {failure !== null && (
        <div className="mt-3">
          <Alert tone="danger" title="تعذّر الفتح">
            {failure}
          </Alert>
        </div>
      )}

      <div className="mt-4 flex flex-wrap items-center gap-3">
        {/*
          ⛔ **ولا `disabled` هنا.** الرأسُ فوقَ يقولُها وFR-013 تقولُها نصّاً: زرٌّ
          رماديٌّ هو «غير مسموح» بوجهٍ ألطف. والجملةُ تحتَه تقولُ السببَ قبلَ
          الضغط، والخادمُ يُجيبُ ٤٢٢ بجملةٍ عربيّةٍ تمرُّ عبرَ `userMessage()` —
          فالرفضُ مقروءٌ في الحالَين، ولا حالةَ يصمتُ فيها المنتَج.
        */}
        <Button onClick={open} loading={pending} loadingLabel="جارٍ الفتح…">
          افتحْ بخصم حصة
        </Button>

        {/*
          ⚠️ حاضرٌ دائماً. عرضُه عندَ العجزِ وحدَه يجعلُ وجودَه إعلاناً عن الرصيد،
          ويجعلُ الشاشةَ شكلَينِ لحالتَين — وهو ما يجعلُ من نسيَ الثانيةَ يشحنُ
          رفضاً بلا مخرج.
        */}
        <Button href={offer.purchase_url} variant="secondary">
          اشترِ رصيداً
        </Button>
      </div>

      {!affordable && (
        <p className="mt-3 text-sm text-ink-muted">
          رصيدُكَ المتاحُ لا يكفي لفتحِ هذه الحصّة. اشترِ رصيداً ثمّ عُدْ إلى هذه
          الصفحة.
        </p>
      )}
    </Card>
  );
}

/**
 * ⚠️ والحالةُ التي لم تكنْ لها مهمّةٌ قطُّ (FR-039ج): فُتِحَت الحصّةُ ثمّ
 * أُرشِفَت مادّتُها. الطالبُ دفعَ ولا شيءَ يُعرَض، وصفحةٌ فارغةٌ بلا جملةٍ هي
 * عطبٌ في نظرِ من دفع.
 */
export function ArchivedAfterUnlockNotice() {
  return (
    <Alert tone="warning" title="انتهت إتاحةُ مادّة هذه الحصّة">
      فتحتَ محتوى هذه الحصّةِ من قبل، وقد انتهت مدّةُ الاحتفاظِ بمادّتِها فلم يعدْ
      فيها ما يُعرَض. لم يُخصَمْ منكَ شيءٌ جديد.
    </Alert>
  );
}
