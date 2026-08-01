import Link from "next/link";
import type { ReactNode } from "react";

export const PROFILE_TABS = [
  { id: "about", label: "نبذة" },
  { id: "courses", label: "الكورسات" },
  { id: "reviews", label: "التقييمات" },
  { id: "schedule", label: "الجدول" },
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
  uuid,
  active,
  children,
}: {
  uuid: string;
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
                    href={`/teachers/${uuid}?tab=${tab.id}`}
                    scroll={false}
                    aria-current={isActive ? "page" : undefined}
                    className={`inline-block whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary ${
                      isActive
                        ? "border-primary text-primary"
                        : "border-transparent text-ink-muted hover:border-line hover:text-ink"
                    }`}
                  >
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
