"use client";

import Link from "next/link";
import type { ReactNode } from "react";

import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";

/**
 * غلافُ بطاقةٍ واحدةٍ على اللوحة — عنوانٌ ورابطُ شاشتِها والحالاتُ الأربع.
 *
 * ⚠️ **الحالاتُ داخلَ البطاقةِ لا حولَها** (`FR-013`). العطلُ المسجَّلُ في صدرِ
 * هذه الشاشةِ هو ثلاثُ قراءاتٍ في وعدٍ واحد: رفضُ واحدةٍ أسقطَ اللوحةَ كلَّها
 * إلى «تعذّر تحميل البيانات» لكلِّ طالبٍ ووليِّ أمرٍ على المنصّة. فما دامَ لا
 * مكوِّنَ يجمعُ قراءتَين، لا يوجدُ مكانٌ يمكنُ أن يقعَ فيه ذلك مرّةً أخرى.
 *
 * ⚠️ و`href` **إلزاميٌّ** (`FR-015`): بطاقةٌ بلا شاشةٍ كاملةٍ تصلُ إليها هي وعدٌ
 * بمكانٍ لا يوجد. ولذلك هو خاصّيّةٌ مطلوبةٌ في النوعِ لا خيارٌ يُنسى.
 *
 * ⚠️ ولا يُضافُ إلى `components/ui/`: تلك المكتبةُ مشتركةٌ عبرَ المنتَجِ ومغلقةُ
 * المتغيّرات، وهذه البطاقةُ خاصّةٌ بشاشةٍ واحدة.
 */
export function DashboardCard({
  title,
  href,
  linkLabel = "عرض الكل",
  loading = false,
  error = null,
  onRetry,
  empty = null,
  children,
}: {
  title: string;
  href: string;
  linkLabel?: string;
  loading?: boolean;
  /** نصٌّ مقروءٌ مرَّ على `userMessage()` — لا خطأٌ خامٌّ أبداً. */
  error?: string | null;
  onRetry?: () => void;
  /**
   * جملةُ «لا بيانات» — وهي **غيرُ** جملةِ «تعذّرَ التحميل» (`FR-014`). تمريرُها
   * `null` يعني أنّ البطاقةَ ترسمُ فراغَها بنفسِها.
   */
  empty?: ReactNode;
  children?: ReactNode;
}) {
  return (
    <section className="rounded-xl border border-line bg-surface-raised p-6">
      <div className="mb-4 flex items-center justify-between gap-4">
        <h3 className="font-semibold text-ink">{title}</h3>
        <Link
          href={href}
          className="shrink-0 rounded text-sm text-primary-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          {linkLabel}
        </Link>
      </div>

      {loading ? (
        <RowsSkeleton count={3} />
      ) : error !== null ? (
        <ErrorState title={error} onRetry={onRetry} />
      ) : (
        (empty ?? children)
      )}
    </section>
  );
}

/**
 * سطرُ «هذا الإذنُ غيرُ ممنوح» مكانَ بطاقةٍ لا تُعرَض (٠٢٩ · `US3`).
 *
 * ⚠️ البطاقةُ بلا إذنٍ **لا تُعرَضُ فارغةً بل لا تُعرَضُ أصلاً**: جدولٌ فارغٌ
 * تحتَ «حصصُ ابنك» جملةٌ كاذبةٌ عن ابنٍ عندَه حصّةٌ غداً، وسببُها إذنٌ يملكُ
 * القارئُ منحَه لنفسِه في خطوةٍ واحدة. فالسطرُ يسمّي الإذنَ ويحملُ الطريقَ إليه.
 */
export function PermissionMissingNote({ label }: { label: string }) {
  return (
    <p className="rounded-xl border border-dashed border-line px-4 py-3 text-sm text-ink-muted">
      {`«${label}» غير ممنوح لك، فلا تظهر هذه البطاقة. `}
      <Link
        href="/family"
        className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        إدارة المرتبطين والأذونات
      </Link>
    </p>
  );
}
