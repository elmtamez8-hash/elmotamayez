import Link from "next/link";
import type { ReactNode } from "react";

import { CoursesIcon, QuestionIcon, ScheduleIcon, StarIcon, UserIcon } from "@/components/icons";

/**
 * ⚠️ الرمزُ **داخلَ الرابطِ لا بجوارَه**، و`aria-hidden` بالبناءِ من `wrap()`:
 * التسميةُ هي النصُّ، فلا يُقرَأُ اللسانُ مرّتَينِ لقارئِ الشاشة. ومن الطقمِ
 * القائمِ لا رسمٌ يُخترَع.
 */
export const PROFILE_TABS = [
  { id: "about", label: "نبذة", Icon: UserIcon },
  { id: "courses", label: "الكورسات", Icon: CoursesIcon },
  { id: "reviews", label: "التقييمات", Icon: StarIcon },
  { id: "schedule", label: "الجدول", Icon: ScheduleIcon },
  /*
   * ⚠️ لساناً لا ذيلاً تحتَ الصفحة. كانَ `FaqAccordion` مرسوماً أسفلَ تفصيلِ
   * الثقةِ بعدَ الألسنةِ كلِّها، فلا يصلُه أحد — وكانَ فارغاً على كلِّ حال، إذ
   * كانَ الخادمُ يُرسِلُ `[]` حرفيّاً. صارَ للحقلِ مخزَنٌ وكاتب، فصارَ له موضع.
   */
  { id: "faq", label: "أسئلة شائعة", Icon: QuestionIcon },
] as const;

export type ProfileTabId = (typeof PROFILE_TABS)[number]["id"];

export function isProfileTab(value: unknown): value is ProfileTabId {
  return PROFILE_TABS.some((tab) => tab.id === value);
}

/**
 * Tabs implemented as links carrying ?tab=, not client state.
 *
 * That is what makes the active tab shareable (FR-062) and keeps every panel's
 * content server-rendered and crawlable. Next.js navigates these without a full
 * reload, so the interaction still feels like a tab switch.
 */
export function ProfileTabs({
  slug,
  active,
  children,
}: {
  slug: string;
  active: ProfileTabId;
  children: ReactNode;
}) {
  return (
    <div>
      {/* A pill rail rather than an underlined strip: the current section is a
          filled burgundy pill, the way the site header marks the current page.
          `overflow-x-auto` stays — five Arabic labels do not fit a phone. */}
      <nav
        aria-label="أقسام ملف المدرّس"
        className="rounded-full border border-line bg-surface-raised p-1.5 shadow-sm"
      >
        <ul className="flex gap-1 overflow-x-auto">
          {PROFILE_TABS.map((tab) => {
            const isActive = tab.id === active;

            return (
              <li key={tab.id} className="shrink-0">
                <Link
                  href={`/teachers/${slug}?tab=${tab.id}`}
                  scroll={false}
                  aria-current={isActive ? "page" : undefined}
                  className={`inline-flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2.5 text-sm transition duration-200 ease-out focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-4 motion-reduce:transition-none ${
                    isActive
                      ? "bg-primary font-extrabold text-white shadow-md shadow-primary/20 focus-visible:outline-white"
                      : "font-semibold text-ink-muted hover:bg-primary-soft hover:text-primary-ink focus-visible:outline-primary"
                  }`}
                >
                  <tab.Icon className="h-4 w-4" />
                  {tab.label}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>

      <div className="py-8">{children}</div>
    </div>
  );
}
