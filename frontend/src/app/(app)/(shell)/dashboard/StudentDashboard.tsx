"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { api } from "@/lib/api";
import type { Order } from "@/lib/types";
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
async function readStart(): Promise<{ fresh: boolean; waiting: Order[] }> {
  try {
    // Every enrolment, any status — an expired course is not a new student — and
    // NOT `?status=active`, which `StatCountsCard` and `ProgressChartCard` share
    // as ONE read (`FR-018`); asking it here first would make that read twice.
    const [enrolments, orders] = await Promise.all([
      api.get<{ meta?: { total?: number } }>("/enrollments"),
      api.get<{ data?: Order[]; meta?: { total?: number } }>("/orders"),
    ]);

    return {
      fresh: (enrolments.meta?.total ?? 0) === 0 && (orders.meta?.total ?? 0) === 0,
      waiting: (orders.data ?? []).filter((order) => order.status === "under_review"),
    };
  } catch {
    return { fresh: false, waiting: [] };
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
 * الطالبُ الجديدُ (مراجعةُ ٢٠٢٦-١٠-٠٩) يرى «أهلاً بك»، وثلاثَ
 * خطواتٍ بدلَ ثماني بطاقاتٍ فارغة، والإشعاراتِ وحدَها معها.
 */
export function StudentDashboard() {
  const { user } = useAuth();
  // null while the answer is out: the hero waits on it, the cards do not exist yet.
  const [fresh, setFresh] = useState<boolean | null>(null);
  const [waiting, setWaiting] = useState<Order[]>([]);

  useEffect(() => {
    let live = true;
    void readStart().then((answer) => {
      if (!live) return;
      setFresh(answer.fresh);
      setWaiting(answer.waiting);
    });

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
      {/* Not «أهلاً بعودتك»: a student who signed up and paid a minute ago lands
          here too, and nothing on the account says how long they have had it. */}
      <DashboardHero
        greeting={`أهلاً، ${name}`}
        line="هذه نظرة عامة على دراستك."
        Icon={LearningIcon}
      />

      {/* The question a student who has just paid opens this screen with. It sat
          at the foot, under five empty cards (journey audit 2026-10-21). */}
      {waiting.length > 0 && (
        <div className="mb-6">
          <Alert tone="warning" title="طلبك قيد المراجعة">
            نراجع إيصال {waiting.map((order) => `«${order.course_title ?? order.store_item_title ?? "طلبك"}»`).join("، ")}
            ، ويُفتح لك ما اشتريته فور اعتماد الدفعة.{" "}
            <Link href="/orders" className="font-semibold underline underline-offset-4">
              صفحة الطلبات
            </Link>
          </Alert>
        </div>
      )}

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
