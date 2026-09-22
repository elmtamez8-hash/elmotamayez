"use client";

import { use, useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import {
  ArchivedAfterUnlockNotice,
  UnlockPanel,
} from "@/components/sessions/UnlockPanel";
import { classSessions, type ClassSession } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { formatSessionDay, formatSessionTime } from "@/lib/session-format";

/**
 * ٠٣٥ · T047 — صفحةُ الحصّةِ للطالب.
 *
 * ⛔ **هذه الصفحةُ هي المخرج.** قبلَها كانَ كلُّ رابطٍ طلّابيٍّ يذهبُ إلى
 * `/sessions/{uuid}/room` — أي إلى بابٍ يُغلَقُ بانتهاءِ النافذةِ ولا يقولُ
 * شيئاً بعدَها. فالطالبُ الذي لم يحضرْ لم يكنْ له مكانٌ يُقالُ له فيه ما حدثَ،
 * ولا مكانٌ يفتحُ منه المحتوى. **سطحٌ بلا رابطٍ ميزةٌ لا يملكُها أحد**، وهذا
 * المستودعُ سجّلَ ثلاثَ حالاتٍ منها في يومٍ واحد — فT048 ينقلُ الروابطَ الخمسةَ
 * إلى هنا، وزرُّ الغرفةِ يعيشُ على هذه الصفحةِ ما دامتِ النافذةُ مفتوحة.
 *
 * ⛔ **والقفلُ يُقرَأُ من الحمولةِ ولا يُعادُ اشتقاقُه في TypeScript.** «مقفول»
 * قرارٌ يجمعُ القعودَ والإعفاءاتِ الأربعةَ والفتحَ المدفوع؛ نسخةٌ ثانيةٌ منه هنا
 * هي بالضبط ما جعلَ تسجيلاً مدفوعاً غيرَ قابلٍ للفتحِ في ٠١٨ — جوابٌ على
 * الشاشةِ وآخرُ عندَ الباب.
 *
 * ⚠️ **و`content_locked === null` تعني «لم يُسأَلْ»** لا «مفتوح»، فلا تُرسَمُ لها
 * لوحةٌ ولا جملة. الخادمُ يختمُ الجوابَ على هذا المسارِ وحدَه وعلى `/schedule`.
 */
export default function SessionPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);

  const [session, setSession] = useState<ClassSession | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setFailure(null);

    try {
      setSession(await classSessions.show(uuid));
    } catch (error) {
      setFailure(userMessage(error));
    } finally {
      setLoading(false);
    }
  }, [uuid]);

  useEffect(() => {
    void load();
  }, [load]);

  /*
    ⚠️ `join_open` IS ANSWERED ONCE, AT FETCH. A student who opens this page
    twenty minutes early would wait in front of a page that never shows
    «دخول الغرفة» without a reload. The server says how long until the door
    opens; we ask again then — silently, with no skeleton over the page.
  */
  const wait = session?.seconds_until_join_open ?? null;

  useEffect(() => {
    // ponytail: only within a day — a page left open for a week can reload.
    if (wait === null || wait <= 0 || wait > 86_400) return;

    const timer = setTimeout(() => {
      classSessions
        .show(uuid)
        .then(setSession)
        .catch(() => undefined);
    }, (wait + 1) * 1000);

    return () => clearTimeout(timer);
  }, [wait, uuid]);

  if (loading) {
    return <RowsSkeleton count={4} />;
  }

  if (failure !== null || session === null) {
    return <ErrorState description={failure ?? "تعذّر تحميل بيانات الحصّة."} onRetry={() => void load()} />;
  }

  const locked = session.content_locked === true;
  const offer = session.content_offer ?? null;

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4">
      <Card as="section" padding="md">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold text-ink">{session.title}</h1>
            <p className="mt-1 text-sm text-ink-muted">
              {formatSessionDay(session.starts_at, session.timezone)} ·{" "}
              {formatSessionTime(session.starts_at, session.timezone)}
              {session.course ? ` · ${session.course.title}` : ""}
              {session.teacher_name ? ` · ${session.teacher_name}` : ""}
            </p>
          </div>

          {/*
            ⚠️ `room_closed` يفوقُ الحالة. البثُّ ينتهي قبلَ أن تلحقَ به الحالةُ
            بمقدارِ نافذةِ الدخول، فشارةُ «جارية» على حصّةٍ انتهت تَعِدُ بغرفةٍ
            تُجيبُ «تعذّر الدخول».
          */}
          <Badge tone={session.room_closed ? "neutral" : "info"}>
            {session.room_closed ? "انتهت" : session.status_label}
          </Badge>
        </div>

        {/*
          زرُّ الغرفةِ ما دامتِ النافذةُ مفتوحة — والجوابُ من الخادم، لأنّ
          النافذةَ صفٌّ في إعداداتِ المنصّةِ وساعةُ الجهازِ قد تكونُ خطأً بساعة.
        */}
        {session.join_open && !session.room_closed && (
          <div className="mt-4">
            <Button href={`/sessions/${session.uuid}/room`}>دخول الغرفة</Button>
          </div>
        )}
      </Card>

      {locked && offer !== null && (
        <UnlockPanel sessionUuid={session.uuid} offer={offer} onOpened={load} />
      )}

      {/*
        ⚠️ مقفولٌ بلا عرض: الحصّةُ لم تُسلَّمْ، أو لا كورسَ لها، أو انتهت إتاحةُ
        مادّتِها — والخادمُ يرفضُ التسعيرَ في الثلاث. صفحةٌ صامتةٌ هنا هي عطبٌ في
        نظرِ من يقرأُها، فتُقالُ الحقيقةُ بدلَ زرٍّ لا يملكُ الخادمُ جواباً له.
      */}
      {locked && offer === null && (
        <Alert tone="info" title="لا يوجدُ محتوى لهذه الحصّة">
          لم يُنشَرْ لهذه الحصّةِ محتوىً بعد، أو لم تُسلَّمْ أصلاً — فليس فيها ما
          يُفتَح، ولم يُخصَمْ منكَ شيء.
        </Alert>
      )}

      {session.content_locked === false && session.recording?.lesson_uuid && (
        <Card as="section" padding="md">
          <h2 className="text-base font-semibold text-ink">تسجيل الحصّة</h2>
          <div className="mt-3">
            <Button href={`/learn/lessons/${session.recording.lesson_uuid}`} variant="secondary">
              افتح التسجيل
            </Button>
          </div>
        </Card>
      )}

      {/*
        FR-039ج — دفعَ ثمنَ الساعةِ ثمّ انتهت مدّةُ الاحتفاظِ بمادّتِها. الفتحُ
        قائمٌ والتسجيلُ ذهبَ، ولا شيءَ آخرُ في المنتَجِ يقولُ ذلك.

        ⛔ **`published` بلا معرِّفِ درسٍ وحدَها.** غيابُ المعرِّفِ على أيِّ حالةٍ
        أخرى يعني «لم يُرفَعْ بعد» — وهي حالُ كلِّ حصّةٍ في دقائقِها الأولى، فشرطٌ
        لا يقرأُ الحالةَ يقولُ لمن حضرَ إنّ مادّتَه ذهبت وهي قيدَ المعالجة. وهذه
        جملةٌ كاذبةٌ تبقى، ولا نجاحٌ لاحقٌ يمحوها.
      */}
      {session.content_locked === false &&
        session.recording?.status === "published" &&
        session.recording.lesson_uuid === null && <ArchivedAfterUnlockNotice />}
    </div>
  );
}
