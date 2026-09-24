"use client";

import { useEffect, useRef, type ReactNode } from "react";

import { InfoIcon, SessionsIcon, UsersIcon } from "@/components/icons";
import { Tabs, TabPanel, useTabParam, type TabDefinition } from "@/components/ui/Tabs";

/**
 * «عن الكورس» · «المجموعات المتاحة» · «حصة خاصة» — ثلاثةٌ في شريطٍ واحد.
 *
 * ⚠️ ثلاثةٌ لا أربعة: **المنهجُ يبقى تحتَ الشريطِ مفتوحاً دائماً**. هو الشيءُ
 * الذي تُفتَحُ الصفحةُ لأجلِه، ودفنُه خلفَ نقرةٍ يجعلُ أطولَ قسمٍ وأهمَّه أقلَّها
 * ظهوراً. والثلاثةُ فوقَه أجوبةٌ قصيرةٌ عن «ما هو» و«متى» و«وحدي؟» — يُقرَأُ منها
 * واحدٌ في المرّة.
 *
 * ⛔ AND `#groups` HAS TO KEEP WORKING, BECAUSE IT IS A LIVE INBOUND LINK.
 * `/teachers/{slug}` appends it to every course card it lists — `CourseCard`'s
 * own docblock is written around that one parameter — so a reader arrives from a
 * teacher's profile asking to see the times. Before tabs it was an anchor down
 * the page; now the section is behind a tab, and an anchor to a hidden panel is
 * a link that silently does nothing. The hash opens the tab.
 *
 * ⚠️ AND THE HASH IS READ ONCE, ON ARRIVAL. Left in a live effect it would drag
 * the reader back to «المجموعات» every time they pressed another tab, because
 * the hash is still in the address bar — the URL does not change when a tab
 * does (`useTabParam` uses `replaceState` on the QUERY, deliberately).
 */

/** The fragment each tab answers to, for a link that arrives pointing at one. */
const ANCHORS: Record<string, string> = {
  "#groups": "groups",
  "#about": "about",
  "#private": "private",
};

/**
 * The event a control ELSEWHERE on the page sends to open one of these tabs.
 *
 * ⚠️ AN EVENT, NOT A PROP OR A LINK. The course page is a server component, so
 * it cannot hand the rail a callback into this strip; and both links are dead
 * from the same page — `?tab=` is read once by `useTabParam`, and the hash once
 * on arrival (the docblock above says why). So «اختر مجموعتك» on the rail used
 * to be text telling the reader where to click. This is the one door between
 * the two, and `openCourseTab()` is its one spelling.
 */
export const OPEN_COURSE_TAB_EVENT = "course-tabs:open";

export function openCourseTab(key: string): void {
  window.dispatchEvent(new CustomEvent<string>(OPEN_COURSE_TAB_EVENT, { detail: key }));
}

export function CourseTabs({
  about,
  groups,
  privateSession,
  groupCount,
}: {
  /** Absent when the teacher wrote no description — the tab goes with it. */
  about: ReactNode;
  groups: ReactNode;
  privateSession: ReactNode;
  groupCount: number;
}) {
  const tabs: TabDefinition[] = [
    ...(about === null ? [] : [{ key: "about", label: "عن الكورس", icon: <InfoIcon /> }]),
    {
      key: "groups",
      label: "المجموعات المتاحة",
      icon: <UsersIcon />,
      // ⚠️ العددُ يُقرَأُ قبلَ النقر: «لا مواعيد» خلفَ تبويبٍ صامتٍ سؤالٌ يُطرَحُ
      // بنقرة. و`Tabs` يُسقِطُ الصفرَ من تلقاءِ نفسِه، فلا يُطبَعُ «٠».
      badge: groupCount,
    },
    { key: "private", label: "حصة خاصة", icon: <SessionsIcon /> },
  ];

  const [active, select] = useTabParam(tabs);
  const arrived = useRef(false);

  useEffect(() => {
    if (arrived.current) return;
    arrived.current = true;

    const wanted = ANCHORS[window.location.hash];

    if (wanted !== undefined && tabs.some((tab) => tab.key === wanted)) {
      select(wanted);
    }
    // Arrival only — see the docblock. `select` and `tabs` are stable enough for
    // the one pass this runs, and the ref is what actually bounds it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const section = useRef<HTMLElement>(null);

  useEffect(() => {
    const onOpen = (event: Event) => {
      const wanted = (event as CustomEvent<string>).detail;
      if (!tabs.some((tab) => tab.key === wanted)) return;

      select(wanted);
      // The rail sits beside the strip on a wide screen and far above it on a
      // phone — the reader has to be taken to what just opened.
      section.current?.scrollIntoView?.({ behavior: "smooth", block: "start" });
    };

    window.addEventListener(OPEN_COURSE_TAB_EVENT, onOpen);
    return () => window.removeEventListener(OPEN_COURSE_TAB_EVENT, onOpen);
  }, [tabs, select]);

  return (
    <section ref={section} className="flex scroll-mt-24 flex-col gap-5">
      <Tabs tabs={tabs} active={active} onChange={select} label="تفاصيل الكورس" />

      {about !== null && (
        <TabPanel tabKey="about" active={active}>
          {about}
        </TabPanel>
      )}

      <TabPanel tabKey="groups" active={active}>
        {groups}
      </TabPanel>

      <TabPanel tabKey="private" active={active}>
        {privateSession}
      </TabPanel>
    </section>
  );
}
