"use client";

import { useCallback, useEffect, useState } from "react";

import { arabicNumber } from "@/lib/numerals";
import { userMessage } from "@/lib/errors";
import type { ComponentType } from "react";

import type { IconProps } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";

/**
 * بطاقةُ رقمٍ واحدٍ خلفَ صلاحيّةٍ واحدة — ثلاثُ بطاقاتٍ في لوحةِ المدرّسِ تُبنى
 * عليها (٠٢٩ · `T023`–`T025`).
 *
 * ⚠️ **مشتركةٌ لأنّ الثلاثةَ سؤالٌ واحدٌ بثلاثِ إجابات**، ونسخُها ثلاثاً يعني أنّ
 * إصلاحَ حالةِ الفشلِ يُطبَّقُ مرّتَينِ ويُنسى الثالثة. وما يختلفُ بينها — المصدرُ
 * والصلاحيّةُ والشاشةُ — يُمرَّرُ، وما يتشابهُ يُكتَبُ هنا مرّةً.
 *
 * ⚠️ ولا بطاقةَ بلا صلاحيّةٍ **ولا نداءَ**: `granted === false` يعني `null` قبلَ
 * أيِّ طلب — سؤالٌ يعرفُ القارئُ أنّه سيُرفَضُ هو ٤٠٣ في سِجِلِّ الخادمِ مقابلَ
 * لا شيءٍ على الشاشة، وهو العطلُ المسجَّلُ في صدرِ هذه الصفحة.
 */
export function CountCard({
  title,
  Icon,
  href,
  linkLabel,
  label,
  granted,
  load: fetchCount,
  empty,
}: {
  title: string;
  Icon: ComponentType<IconProps>;
  href: string;
  linkLabel?: string;
  /** الجملةُ تحتَ الرقم — «ورقة بانتظار التصحيح». */
  label: string;
  granted: boolean;
  /**
   * ⚠️ **مرجعٌ ثابتٌ من نطاقِ الوحدة، لا دالّةٌ سهميّةٌ في الوسائط.** الثانيةُ
   * تُبنى في كلِّ تصييرٍ فتدخُلُ في اعتماداتِ `useCallback` بهُويّةٍ جديدةٍ كلَّ
   * مرّة — حلقةُ جلبٍ لا نهائيّة. والمُنادونَ الثلاثةُ يُعرِّفونَها فوقَ
   * مكوّناتِهم، فالاعتمادُ صادقٌ ولا تعطيلَ لقاعدةٍ يُكتَبُ بجوارِه.
   */
  load: () => Promise<number>;
  /** جملةُ الصفر: «لا شيء ينتظرك» ليست رقماً بل خبرٌ جيّد. */
  empty: string;
}) {
  const [count, setCount] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    if (!granted) return;

    setLoading(true);
    setError(null);

    fetchCount()
      .then(setCount)
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [granted, fetchCount]);

  useEffect(load, [load]);

  if (!granted) return null;

  return (
    <DashboardCard
      title={title}
      Icon={Icon}
      href={href}
      linkLabel={linkLabel}
      loading={loading}
      error={error}
      onRetry={load}
      empty={!loading && error === null && count === 0 ? (
        <p className="text-sm text-ink-muted">{empty}</p>
      ) : null}
    >
      <p className="text-3xl font-bold text-ink">
        <bdi>{count === null ? "—" : arabicNumber(count)}</bdi>
      </p>
      <p className="text-sm text-ink-muted">{label}</p>
    </DashboardCard>
  );
}
