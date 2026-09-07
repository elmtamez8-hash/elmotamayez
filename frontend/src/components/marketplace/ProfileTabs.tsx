import Link from "next/link";
import type { ReactNode } from "react";

import { CoursesIcon, ScheduleIcon, StarIcon, UserIcon } from "@/components/icons";

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
      <div className="border-b border-line">
        <nav aria-label="أقسام ملف المدرّس">
          <ul className="-mb-px flex gap-1 overflow-x-auto">
            {PROFILE_TABS.map((tab) => {
              const isActive = tab.id === active;

              return (
                <li key={tab.id}>
                  <Link
                    href={`/teachers/${slug}?tab=${tab.id}`}
                    scroll={false}
                    aria-current={isActive ? "page" : undefined}
                    className={`inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary ${
                      isActive
                        ? "border-primary text-primary-ink"
                        : "border-transparent text-ink-muted hover:border-line hover:text-ink"
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
      </div>

      <div className="py-8">{children}</div>
    </div>
  );
}
