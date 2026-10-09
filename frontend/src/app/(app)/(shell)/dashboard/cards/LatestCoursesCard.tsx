"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { api } from "@/lib/api";
import { teachesOnPlatform, useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { statusLabel, statusTone } from "@/lib/labels";
import { can, P } from "@/lib/permissions";
import type { Course } from "@/lib/types";
import { Badge } from "@/components/ui/Badge";
import { CoursesIcon } from "@/components/icons";
import { DashboardCard, ROW_CLASS, ROW_LINK_CLASS, RowIcon } from "./DashboardCard";
import { isRefusal } from "./shared-read";

const SHOWN = 5;

/**
 * آخرُ كورساتِ مكانِ العملِ وحالتُها، برابطٍ إلى محرِّرِ كلٍّ منها.
 *
 * ⚠️ **الحراسةُ `courses.update` لا `courses.view`، والفرقُ ثلاثةُ أبوابٍ مغلقة.**
 * `/courses` يقبلُ `courses.view` — والطالبُ يحملُها — لكنّ الشاشةَ التي تفتحُها
 * البطاقةُ (`‎/manage/courses`) محروسةٌ بـ`courses.update` في `panel-nav.tsx`،
 * ورابطُ كلِّ صفٍّ هو المحرِّر، والخادمُ نفسُه **يُسقِطُ المسوّداتِ** عمّن لا
 * يملكُ التعديل — فـ«منشور / مسودة» لا تعني شيئاً إلّا لحاملِها.
 *
 * ⚠️ و**لا قراءةَ بلا مكانِ عمل** (`teachesOnPlatform`): السياقُ العدمُ يُسقِطُ
 * `WorkspaceScope`، فمالكُ المنصّةِ كانَ سيرى كورساتِ كلِّ مدرّسٍ على المنصّةِ
 * تحتَ عنوانٍ يقولُ إنّها كورساتُه.
 *
 * والترتيبُ من الخادم: `created_at` تنازليّاً، و`per_page` يُحترَمُ من ١ إلى ٢٠٠.
 */
export function LatestCoursesCard() {
  const { user } = useAuth();
  const granted = can(user, P.coursesUpdate) && teachesOnPlatform(user);

  const [rows, setRows] = useState<Course[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refused, setRefused] = useState(false);

  const load = useCallback(() => {
    if (!granted) return;

    setLoading(true);
    setError(null);

    api
      .get<{ data: Course[] }>(`/courses?per_page=${SHOWN}`)
      .then((result) => setRows((result.data ?? []).slice(0, SHOWN)))
      .catch((err) => (isRefusal(err) ? setRefused(true) : setError(userMessage(err))))
      .finally(() => setLoading(false));
  }, [granted]);

  useEffect(load, [load]);

  if (!granted || refused) return null;

  return (
    <DashboardCard
      title="الكورسات"
      Icon={CoursesIcon}
      href="/manage/courses"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && rows.length === 0 ? (
          // بلا رابطِ «أنشئ»: الإنشاءُ `courses.create` لا `courses.update`، والشاشةُ
          // خلفَ «عرض الكل» تعرضُ زرَّها لمن يملكُه.
          <p className="text-sm text-ink-muted">لا كورسات بعد.</p>
        ) : null
      }
    >
      <ul className="space-y-2">
        {rows.map((course) => (
          <li key={course.uuid}>
            <Link
              href={`/manage/courses/${course.uuid}`}
              className={`${ROW_CLASS} ${ROW_LINK_CLASS} items-center`}
            >
              <RowIcon icon={<CoursesIcon className="h-4 w-4" />} />
              <span className="min-w-0 flex-1 truncate text-sm font-bold text-ink">{course.title}</span>
              <Badge tone={statusTone(course.status)}>{statusLabel(course.status)}</Badge>
            </Link>
          </li>
        ))}
      </ul>
    </DashboardCard>
  );
}
