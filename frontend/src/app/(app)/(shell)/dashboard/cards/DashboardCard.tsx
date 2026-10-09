"use client";

import Link from "next/link";
import type { ComponentType, ReactNode } from "react";

import type { IconProps } from "@/components/icons";

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
 * ⚠️ و`href` **اختياريٌّ في النوعِ لا في القاعدة** (`FR-015`): كلُّ بطاقةٍ لها
 * شاشةٌ كاملة، لكنّ **صلاحيّةَ القارئِ** هي التي تقرّرُ هل يُعرَضُ الرابطُ إليها.
 * مساعدٌ يقرأُ الحصصَ (`sessions.view`) ولا يُديرُها يرى جدولَ مكانِ العمل، و
 * «عرض الكل» إلى `‎/manage/sessions` كان سيفتحُ له شاشةً تحرسُها `sessions.manage`
 * في القائمةِ الجانبيّة — رابطٌ إلى بابٍ مغلق. فالبطاقةُ تُمرِّرُ `undefined`
 * حينَها، ولا يُمرَّرُ `undefined` لبطاقةٍ **لا شاشةَ لها أصلاً**.
 *
 * ⚠️ ولا يُضافُ إلى `components/ui/`: تلك المكتبةُ مشتركةٌ عبرَ المنتَجِ ومغلقةُ
 * المتغيّرات، وهذه البطاقةُ خاصّةٌ بشاشةٍ واحدة.
 */
export function DashboardCard({
  title,
  Icon,
  href,
  linkLabel = "عرض الكل",
  loading = false,
  error = null,
  onRetry,
  empty = null,
  children,
}: {
  title: string;
  /**
   * أيقونةُ البطاقة — **من الطقمِ القائمِ في `components/icons`، لا رسمٌ يُخترَعُ
   * للمناسبة**، وهي القاعدةُ التي كتبَتها `AXIS_ICONS` في شاشةِ التقييماتِ من
   * قبل: أربعُ أيقوناتٍ متقاربةٌ أسوأُ من أربعةِ عناوين.
   *
   * ⚠️ و`aria-hidden` بالبناءِ: `wrap()` يُخفيها ما لم تحملْ `title`، فالعنوانُ
   * بجوارَها هو النصُّ ولا يُقرَأُ الرمزُ مرّتَين.
   */
  Icon?: ComponentType<IconProps>;
  /** بلا قيمةٍ = القارئُ لا يملكُ فتحَ الشاشة، فلا رابطَ يُعرَض. */
  href?: string;
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
    /*
     | ⚠️ `break-inside-avoid` على البطاقةِ نفسِها لا على الحاوية. الشبكةُ كانت
     | تُمدِّدُ كلَّ خليّةٍ إلى ارتفاعِ أطولِ بطاقةٍ في صفِّها (`align-items:
     | stretch` هو الافتراض)، فبطاقةُ الإشعاراتِ بثلاثةِ أسطرٍ بجوارَ جدولٍ بخمسةِ
     | صفوفٍ تحملُ فراغَ سطرَينِ داخلَها. والحاوياتُ أعمدةُ CSS الآن، فالبطاقةُ
     | تأخذُ ارتفاعَها الطبيعيَّ وتُحزَمُ التاليةُ تحتَها مباشرةً — وهذه الخاصّيّةُ
     | هي ما يمنعُ انقسامَ بطاقةٍ بينَ عمودَين.
     |
     | ⚠️ **والهامشُ هنا لا على الحاوية.** أعمدةُ CSS لا تعرفُ `gap` رأسيّاً —
     | `gap` فيها تباعدُ الأعمدةِ وحدَه — فالفاصلُ بينَ بطاقةٍ والتي تحتَها هامشُها
     | هي. وكانَ متغيّراً على الحاويةِ (`[&>*]:mb-6`) فحُذِف: لا نظيرَ لتلك الصيغةِ
     | في هذا المستودعِ كلِّه، و`mb-6` أداةٌ عاديّةٌ مستعملةٌ في مئاتِ المواضع.
     |
     | و`banner-rise` هو الحركةُ القائمةُ في `globals.css` — لا حركةَ جديدةٌ —
     | وكتلةُ `prefers-reduced-motion` هناك تُصفِّرُها لمن طلبَ ذلك.
     */
    <section className="banner-rise group mb-6 break-inside-avoid rounded-3xl border border-line bg-surface-raised p-6 shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 motion-reduce:transition-none motion-reduce:hover:translate-y-0">
      <div className="mb-4 flex items-center justify-between gap-4">
        <h3 className="flex min-w-0 items-center gap-3 text-lg font-extrabold text-ink">
          {Icon !== undefined && (
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink transition duration-300 group-hover:rotate-6 group-hover:bg-primary group-hover:text-white motion-reduce:group-hover:rotate-0">
              <Icon className="h-5 w-5" />
            </span>
          )}
          <span className="min-w-0 text-balance leading-snug">{title}</span>
        </h3>
        {href !== undefined && (
          <Link
            href={href}
            className="shrink-0 rounded-full bg-primary-soft px-3 py-1.5 text-sm font-bold text-primary-ink transition-colors hover:bg-primary hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {linkLabel}
          </Link>
        )}
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

/**
 * The row every list card on the dashboard draws: an icon tile, then the text.
 * One spelling so the notifications, the courses, the papers and the sessions
 * read as one family — and the tile is decoration, its meaning is in the text.
 *
 * `tone` is the tile's colour (`TONE_CLASSES`, or a category's own); `linked`
 * gives the hover a row that is itself a link.
 */
export const ROW_CLASS =
  "group/row flex gap-3 rounded-2xl border border-line p-3 transition duration-200";

export const ROW_LINK_CLASS =
  "hover:border-primary/40 hover:bg-primary-soft/30 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary";

export function RowIcon({ icon, tone = "bg-primary-soft text-primary-ink" }: { icon: ReactNode; tone?: string }) {
  return (
    <span
      aria-hidden="true"
      className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl transition duration-200 group-hover/row:scale-110 motion-reduce:group-hover/row:scale-100 ${tone}`}
    >
      {icon}
    </span>
  );
}
