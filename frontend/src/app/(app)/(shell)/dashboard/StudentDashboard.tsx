"use client";

import { useEffect, useState } from "react";

import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { LearningIcon } from "@/components/icons";
import { DashboardHero } from "./DashboardHero";
import { CourseBalancesCard } from "./cards/CourseBalancesCard";
import { LatestNotificationsCard } from "./cards/LatestNotificationsCard";
import { LatestOrdersCard } from "./cards/LatestOrdersCard";
import { ProgressChartCard } from "./cards/ProgressChartCard";
import { QuickLinksCard } from "./cards/QuickLinksCard";
import { StartStepsCard } from "./cards/StartStepsCard";
import { StatCountsCard } from "./cards/StatCountsCard";
import { UpcomingSessionsCard } from "./cards/UpcomingSessionsCard";

/**
 * «New» is nothing started and nothing bought: no enrolment of any status, and
 * no order — a student whose transfer awaits approval is not lost, and
 * their order card is the thing they came to see.
 *
 * ⚠️ A FAILED READ IS «NOT NEW». The full dashboard is the safe answer: every
 * card there owns its own error state, while the start screen would tell a
 * paying student to go and find a teacher.
 */
async function isNewStudent(): Promise<boolean> {
  const total = async (path: string) =>
    (await api.get<{ meta?: { total?: number } }>(path)).meta?.total ?? 0;

  try {
    // Every enrolment, any status — an expired course is not a new student — and
    // NOT `?status=active`, which `StatCountsCard` and `ProgressChartCard` share
    // as ONE read (`FR-018`); asking it here first would make that read twice.
    const [enrolments, orders] = await Promise.all([total("/enrollments"), total("/orders")]);

    return enrolments === 0 && orders === 0;
  } catch {
    return false;
  }
}

/**
 * ما يراهُ الطالبُ حينَ يفتحُ اللوحة (٠٢٩ · `US1`).
 *
 * ⚠️ **لا وعدَ مجموعاً في هذا الملفّ** لبطاقاتِ اللوحة: كلُّ بطاقةٍ تجلبُ
 * بنفسِها وتملكُ حالاتِها الأربع (`FR-013`). السؤالُ الوحيدُ هنا «هل هو جديد؟»،
 * وفشلُه يعرضُ اللوحةَ الكاملة لا شاشةً فارغة.
 *
 * ⚠️ وكلُّ قراءةٍ هنا قراءةٌ **يملكُها الطالب**: لا `‎/courses` (فهرسُ التأليف،
 * محروسٌ بصلاحيّةِ مساحةِ عمل)، ولا شاشةَ إدارةٍ في الروابطِ السريعةِ — تلك
 * تُرشَّحُ بـ`allowedNav` قبلَ أن تصلَ إلى هنا.
 *
 * الطالبُ الجديدُ (مراجعةُ ٢٠٢٦-١٠-٠٩) يرى «أهلاً بك» لا «أهلاً بعودتك»، وثلاثَ
 * خطواتٍ بدلَ ثماني بطاقاتٍ فارغة، والإشعاراتِ وحدَها معها.
 */
export function StudentDashboard() {
  const { user } = useAuth();
  // null while the answer is out: the hero waits on it, the cards do not exist yet.
  const [fresh, setFresh] = useState<boolean | null>(null);

  useEffect(() => {
    let live = true;
    void isNewStudent().then((answer) => live && setFresh(answer));

    return () => {
      live = false;
    };
  }, []);

  const name = user?.first_name ?? "";

  if (fresh === null) {
    return <DashboardHero greeting={`أهلاً، ${name}`} line="نجهّز لوحتك…" Icon={LearningIcon} />;
  }

  if (fresh) {
    return (
      <div>
        <DashboardHero greeting={`أهلاً بك، ${name}`} line="حسابك جاهز. هذه أسرع طريق إلى أول درس." Icon={LearningIcon} />
        <StartStepsCard />
        <LatestNotificationsCard />
      </div>
    );
  }

  return (
    <div>
      <DashboardHero
        greeting={`أهلاً بعودتك، ${name}`}
        line="هذه نظرة عامة على دراستك."
        Icon={LearningIcon}
      />

      <StatCountsCard />

      {/*
        ⚠️ **أعمدةُ CSS لا شبكة، والفرقُ هو الفراغُ الذي بلَّغَ عنه القارئ.**
        `grid` يُمدِّدُ كلَّ خليّةٍ إلى ارتفاعِ أطولِ بطاقةٍ في صفِّها، فبطاقةٌ
        بثلاثةِ أسطرٍ بجوارَ جدولٍ بخمسةِ صفوفٍ تحملُ فراغَ سطرَينِ داخلَها.
        والأعمدةُ تُعطي كلَّ بطاقةٍ ارتفاعَها الطبيعيَّ وتحزِمُ التاليةَ تحتَها.

        ⚠️ **والتباعدُ على الأبناءِ المباشرينَ بلا غلافٍ إضافيّ**: بطاقاتُ العددِ
        الثلاثُ تُعيدُ `null` بلا صلاحيّة، وغلافٌ حولَ كلٍّ منها كان سيتركُ
        `div` فارغاً بهامشِه — أي الفراغَ نفسَه الذي جاءَ هذا التغييرُ يحذفُه.
      */}
      <div className="columns-1 gap-6 lg:columns-2">
        {/* الحصّةُ القادمةُ أوّلاً: «متى حصّتي؟» هو السؤالُ الذي تُفتَحُ به هذه
            الشاشةُ، والرصيدُ بعدَه لأنّه ما يمنعُ حجزَ التالية. */}
        <UpcomingSessionsCard />
        <CourseBalancesCard />
        {/* الرسمُ تحتَ رقمِ «كورسات جارية» مباشرةً ومن ردِّه نفسِه: الرقمُ يقولُ
            كم، والأشرطةُ تقولُ أين وصلَ في كلٍّ منها. */}
        <ProgressChartCard />
        <LatestNotificationsCard />
        <LatestOrdersCard />
      </div>

      <QuickLinksCard />
    </div>
  );
}
