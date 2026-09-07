"use client";

import { useCallback, useEffect, useState } from "react";

import { Table, type Column } from "@/components/ui/Table";
import { arabicNumber } from "@/lib/numerals";
import type { GuardianRelation } from "@/lib/notifications";
import { readChildAttendance } from "./ChildAttendanceCard";
import { readChildBalances } from "./ChildBalanceCard";
import { readChildReportCards } from "./ChildReportCardCard";
import { readChildSchedule } from "./ChildScheduleCard";
import { FamilyIcon } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";

/**
 * صفٌّ لكلِّ ابنٍ في مكانٍ واحد (٠٢٩ · طلبُ ٢٠٢٦-٠٩-٠٧).
 *
 * ⚠️ **الأذونُ لكلِّ ابنٍ على حِدة، والخليةُ بلا إذنٍ تقولُ ذلك ولا تقولُ صفراً.**
 * هذا هو الفرقُ الذي تُخفيه المقارنةُ بطبيعتِها: «٠٪ حضور» و«لم يُؤذَنْ لكِ
 * بالحضور» جملتانِ عن ابنَين مختلفَين تماماً، والأولى تُهمةٌ في حقِّ ابنٍ لم
 * يُسأَلْ عنه أحد. القاعدةُ نفسُها التي تجعلُ بطاقةً بلا إذنٍ **تغيبُ** بدلَ أن
 * تُرسَمَ فارغة.
 *
 * ⚠️ **ولا مجموعَ أرصدة.** «الأرصدةُ لا تُجمَع» قاعدةٌ مكتوبةٌ في هذا المستودعِ
 * ولها سببُها: الحجبُ لكلِّ كورسٍ لا لكلِّ شخص، و+١٠ في الرياضيّاتِ مع −٦ في
 * الفيزياء تُقرَأُ +٤ «غيرَ محجوب» بينما الفيزياءُ مقفولة. فالعمودُ **أدنى
 * رصيدٍ بينَ كورساتِه** — أرضيّةٌ لا حاصلُ جمع، وهي الرقمُ الذي يقولُ «هذا الابنُ
 * على وشكِ التوقّف».
 *
 * ⚠️ **ولا طلبَ إضافيٌّ للابنِ المعروض**: القراءاتُ الأربعُ كلُّها تمرُّ بـ
 * `sharedRead`، والبطاقاتُ تحتَها تُصيَّرُ في التمريرةِ نفسِها فتقتسمُ الردَّ.
 *
 * ⚠️ ولا يظهرُ لابنٍ واحد: «مقارنةٌ» بصفٍّ واحدٍ جدولٌ يصفُ نفسَه.
 */
type Row = {
  uuid: string;
  name: string;
  granted: string[];
  attendancePct: number | null;
  upcoming: number | null;
  lowestCredits: number | null;
  overallPct: number | null;
};

/** كلُّ قراءةٍ تُمسِكُ عطبَها: صفٌّ ناقصٌ خيرٌ من جدولٍ لا يُرسَم. */
async function quietly<T>(read: () => Promise<T>): Promise<T | null> {
  try {
    return await read();
  } catch {
    return null;
  }
}

async function rowFor(relation: GuardianRelation): Promise<Row> {
  const uuid = relation.student_uuid as string;
  const granted = relation.permissions.map((permission) => permission.key);
  const may = (key: string) => granted.includes(key);

  const [attendance, schedule, balances, cards] = await Promise.all([
    may("attendance") ? quietly(() => readChildAttendance(uuid)) : null,
    may("schedule") ? quietly(() => readChildSchedule(uuid)) : null,
    may("payments") ? quietly(() => readChildBalances(uuid)) : null,
    may("results") ? quietly(() => readChildReportCards(uuid)) : null,
  ]);

  const credits = (balances?.data ?? []).map((balance) => balance.remaining_credits);

  const published = (cards?.data ?? [])
    .filter((card) => card.published_at !== null && card.overall_pct !== null)
    .sort((a, b) => b.period_end.localeCompare(a.period_end));

  return {
    uuid,
    name: relation.student_name,
    granted,
    attendancePct: attendance?.data.rate_pct ?? null,
    upcoming: schedule === null ? null : (schedule.data ?? []).length,
    lowestCredits: credits.length === 0 ? null : Math.min(...credits),
    overallPct: published[0]?.overall_pct ?? null,
  };
}

export function ChildrenComparisonCard({ options }: { options: GuardianRelation[] }) {
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // البصمةُ هي المعرِّفاتُ لا مرجعُ المصفوفة، وإلّا أُعيدَ الجلبُ في كلِّ تصيير.
  const signature = options.map((relation) => relation.student_uuid).join("|");

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    void Promise.all(options.map(rowFor))
      .then((result) => {
        setRows(result);
        setError(null);
      })
      .catch(() => setError("تعذّر تحميل المقارنة"))
      .finally(() => setLoading(false));
    /*
     | ⚠️ البصمةُ لا المصفوفة. `options` مرجعٌ جديدٌ في كلِّ تصييرٍ لأنّها مشتقّةٌ
     | من `selectableChildren()` فوق، فوضعُها في الاعتمادِ جلبٌ لا يتوقّف — أربعُ
     | قراءاتٍ لكلِّ ابنٍ في كلِّ تصيير. والمعرِّفاتُ هي ما يُغيِّرُ الجواب.
     */
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature]);

  useEffect(load, [load]);

  if (options.length < 2) return null;

  return (
    <DashboardCard
      title="مقارنة سريعة"
      Icon={FamilyIcon}
      href="/family"
      linkLabel="إدارة المرتبطين"
      loading={loading}
      error={error}
      onRetry={load}
    >
      <Table
        caption="مقارنة بين الأبناء المرتبطين بحسابك"
        columns={COLUMNS}
        rows={rows}
        rowKey={(row) => row.uuid}
      />
    </DashboardCard>
  );
}

/** «غير ممنوح» ليست «—»: الأولى إذنٌ لم يُمنَحْ والثانيةُ رقمٌ لم يصل. */
function cell(row: Row, permission: string, value: number | null, suffix = "") {
  if (!row.granted.includes(permission)) {
    return <span className="text-xs text-ink-muted">غير ممنوح</span>;
  }

  return value === null ? "—" : `${arabicNumber(value)}${suffix}`;
}

const COLUMNS: Column<Row>[] = [
  { key: "name", header: "الابن", render: (row) => row.name },
  {
    key: "attendance",
    header: "الحضور (٣٠ يوماً)",
    numeric: true,
    render: (row) => cell(row, "attendance", row.attendancePct, "٪"),
  },
  {
    key: "upcoming",
    header: "حصص قادمة",
    numeric: true,
    render: (row) => cell(row, "schedule", row.upcoming),
  },
  {
    key: "credits",
    header: "أدنى رصيد",
    numeric: true,
    render: (row) => cell(row, "payments", row.lowestCredits),
  },
  {
    key: "overall",
    header: "آخر معدّل",
    numeric: true,
    render: (row) => cell(row, "results", row.overallPct, "٪"),
  },
];
