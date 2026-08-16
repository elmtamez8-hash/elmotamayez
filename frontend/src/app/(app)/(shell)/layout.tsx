"use client";

import { useAuth } from "@/lib/auth-context";
import { useRouter, usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode, type ComponentType } from "react";
import Link from "next/link";
import { PLATFORM_NAME } from "@/lib/platform";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { NotificationBell } from "@/components/app/NotificationBell";
import { P, can } from "@/lib/permissions";
import { TONE_CLASSES } from "@/lib/labels";
import { grading } from "@/lib/grading";
import {
  BellIcon,
  CertificateIcon,
  CloseIcon,
  CoursesIcon,
  CreditsIcon,
  FamilyIcon,
  GradingIcon,
  HomeIcon,
  ExamIcon,
  LearningIcon,
  LogoutIcon,
  MembersIcon,
  MenuIcon,
  MistakesIcon,
  OrdersIcon,
  PracticeIcon,
  ItemAnalysisIcon,
  QuestionBankIcon,
  ScheduleIcon,
  SessionsIcon,
  SettingsIcon,
  SettlementIcon,
  WorkspaceIcon,
  type IconProps,
} from "@/components/icons";

/**
 * `permission` absent means everyone who is signed in may see it.
 *
 * ⚠️ THE LIST USED TO BE FLAT AND UNGATED, and that is what the student was
 * complaining about: the course editor, the exam builder, the question bank, the
 * settlement statement and the whole «الإدارة» block were offered to every
 * account. The server refused each one — this was never an authorisation hole —
 * but a menu of links that answer 403 teaches the reader that the product does
 * not know who they are.
 */
type NavItem = {
  href: string;
  label: string;
  Icon: ComponentType<IconProps>;
  permission?: string;
  /** Renders the waiting count beside the label — see `pendingGrading` below. */
  badge?: "grading";
};

const mainNav: NavItem[] = [
  { href: "/dashboard", label: "لوحة التحكم", Icon: HomeIcon },
  // /courses is the public marketplace listing; course management lives under
  // /manage so the two do not resolve to the same route.
  { href: "/manage/courses", label: "الكورسات", Icon: CoursesIcon, permission: P.coursesUpdate },
  // /schedule is the student's own timetable across every teacher;
  // /manage/sessions is the teacher's calendar. Two screens, two audiences —
  // collapsing them into one route would make each show the other half nothing.
  { href: "/schedule", label: "جدولي", Icon: ScheduleIcon },
  { href: "/manage/sessions", label: "حصصي", Icon: SessionsIcon, permission: P.sessionsManage },
  // The teacher's own money. /orders is the student's side and is a different
  // question with different permissions — SETTLEMENT_STATEMENT_VIEW reaches only
  // the teacher, never their assistant.
  { href: "/manage/settlement", label: "كشف التسوية", Icon: SettlementIcon, permission: P.settlementStatement },
  { href: "/enrollments", label: "تعلّمي", Icon: LearningIcon },
  // ⚠️ The student's own notebook, and it needs its own entry. It is derived
  // from answers rather than authored, so nothing in the product would ever link
  // to it — a screen reachable only by typing its address is a screen nobody
  // opens.
  { href: "/mistakes", label: "دفتر أخطائي", Icon: MistakesIcon },
  // Building your own paper is a different act from reading what you got wrong:
  // one starts from the bank and the other from your own history. Two entries,
  // because a student who wants to revise a topic they have never been tested on
  // would never look for it inside a notebook of mistakes.
  { href: "/practice", label: "درّب نفسك", Icon: PracticeIcon },
  { href: "/exams", label: "الاختبارات", Icon: ExamIcon },
  // The teacher's own question library. Separate from /exams, which is the
  // student's list of what they may sit: one question here serves three exams
  // there, and collapsing them would make the bank look like a fourth exam.
  { href: "/manage/bank", label: "بنك الأسئلة", Icon: QuestionBankIcon, permission: P.bankView },
  // ⚠️ The analysis needs its own entry, not a tab inside the bank. It answers a
  // different question — "which of these is failing my students" rather than
  // "what do I have" — and a screen reachable only from another screen is a
  // screen nobody opens.
  { href: "/manage/analytics/questions", label: "تحليل الأسئلة", Icon: ItemAnalysisIcon, permission: P.analyticsView },
  // ⚠️ WITH ITS COUNT, and the count is the whole reason the entry earns a
  // place. A paper waiting to be marked is a student waiting for a result they
  // were told was coming — and unlike every other screen here, nothing else in
  // the product tells the teacher it is there. A link with no number is one they
  // remember to open on the days they were already going to.
  { href: "/manage/grading", label: "لوحة التصحيح", Icon: GradingIcon, permission: P.gradingPerform, badge: "grading" },
  { href: "/certificates", label: "الشهادات", Icon: CertificateIcon },
  { href: "/orders", label: "الطلبات", Icon: OrdersIcon },
  // The student's credits, counted in sessions and never in money. Separate
  // from /orders, which is one payment at a time: this is the standing balance
  // those payments produce, per course.
  { href: "/billing", label: "رصيدي", Icon: CreditsIcon },
  { href: "/notifications", label: "الإشعارات", Icon: BellIcon },
  { href: "/family", label: "المرتبطون", Icon: FamilyIcon },
];

const adminNav: NavItem[] = [
  // How this academy collects. Under admin, not beside /billing: that one is the
  // student's own balance, this one is the policy that produces it.
  { href: "/manage/billing/settings", label: "إعدادات الفوترة", Icon: CreditsIcon, permission: P.billingSettings },
  // Who has sessions left and who has stopped. Beside the policy rather than
  // under /manage/sessions, because it answers a money question about students
  // — in credits only, never in money.
  { href: "/manage/billing/students", label: "أرصدة الطلاب", Icon: CreditsIcon, permission: P.billingBalanceView },
  // Exam season, when nothing is deferred. Its own entry rather than a switch on
  // the settings screen: it is a period on a calendar with a start and an end,
  // not a preference, and it expires by itself.
  { href: "/manage/billing/exam-mode", label: "وضع الامتحانات", Icon: CreditsIcon, permission: P.billingExamMode },
  { href: "/workspaces", label: "مساحات العمل", Icon: WorkspaceIcon },
  { href: "/members", label: "الأعضاء", Icon: MembersIcon, permission: P.membersView },
  { href: "/settings", label: "الإعدادات", Icon: SettingsIcon },
];

/*
 * ⚠️ ITS OWN ARRAY, SHOWN TO THE SUPER ADMIN ALONE — and that is not decoration.
 * Every entry above is a workspace question a teacher may legitimately ask;
 * `billing.collection.view` is held by no tenant role at all, so putting the
 * reconciliation beside "إعدادات الفوترة" would show every teacher on the
 * platform a link that answers 403. A menu item nobody may open is worse than a
 * missing one: it reads as something broken rather than something private.
 *
 * The server is still the guard — this array only decides what is offered.
 */
const platformNav: NavItem[] = [
  // What the hourly payment sweep found: money that settled without telling us,
  // and what it could not resolve on its own.
  { href: "/manage/payments/reconciliation", label: "تسوية المدفوعات", Icon: CreditsIcon, permission: P.billingCollection },
  // Every financial decision and the terminal it came from. Beside the
  // reconciliation rather than under it: one asks what the machine could not
  // settle, the other asks what people decided — and an auditor opens the second
  // when the first has already been dealt with.
  { href: "/manage/payments/audit", label: "سجلّ التدقيق المالي", Icon: OrdersIcon, permission: P.billingAudit },
  // What came in, over a period. Beside the two above rather than under the
  // teacher's billing screens: this is the platform's collection across every
  // workspace, and a teacher holding every tenant permission there is cannot
  // open it.
  { href: "/manage/payments/collection", label: "سجلّ التحصيل", Icon: CreditsIcon, permission: P.billingCollection },
];

const allNav = [...mainNav, ...adminNav, ...platformNav];

export default function ShellLayout({ children }: { children: ReactNode }) {
  const { user, loading, logout } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  // Below `md` the 16rem sidebar is wider than half a phone and sits over the
  // page, so it is a drawer there and permanent from `md` up. Without this the
  // panel is not merely cramped on a phone — the nav intercepts every click
  // meant for the content behind it.
  const [navOpen, setNavOpen] = useState(false);
  const [pendingGrading, setPendingGrading] = useState(0);

  useEffect(() => {
    if (!loading && !user) {
      router.push("/login");
    }
  }, [user, loading, router]);

  // Closed on every navigation. The drawer sits above the page on a phone, so
  // one left open covers the screen the link just went to.
  useEffect(() => setNavOpen(false), [pathname]);

  /*
   * How many papers are waiting on this teacher.
   *
   * ⚠️ RE-READ ON EVERY NAVIGATION, NOT POLLED. The bell polls because an
   * eviction has to reach a page nobody is touching; a grading count does not —
   * the teacher who just marked a paper is the one whose number changed, and
   * they navigate immediately afterwards. A second interval on every panel page
   * would be a request a minute, for ever, for a number that moves twice a day.
   */
  useEffect(() => {
    if (!can(user, P.gradingPerform)) return;

    grading
      .queue(1, 1)
      .then((response) => setPendingGrading(response.meta?.total ?? 0))
      // Silent: a failed count must never take the sidebar down with it.
      .catch(() => setPendingGrading(0));
  }, [user, pathname]);

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-ink-muted">جارٍ التحميل…</p>
      </div>
    );
  }

  if (!user) return null;

  const allowed = (items: NavItem[]) => items.filter((item) => can(user, item.permission));

  const renderItem = ({ href, label, Icon, badge }: NavItem) => {
    const active = pathname === href || pathname.startsWith(href + "/");
    return (
      <Link
        key={href}
        href={href}
        aria-current={active ? "page" : undefined}
        className={`mb-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
          active
            ? "bg-primary font-semibold text-white"
            : "text-ink hover:bg-primary-soft hover:text-primary-ink"
        }`}
      >
        <Icon className="h-5 w-5" />
        <span className="flex-1">{label}</span>
        {badge === "grading" && pendingGrading > 0 && (
          <span
            className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
              active ? "bg-white/20 text-white" : TONE_CLASSES.warning
            }`}
          >
            <bdi>{pendingGrading}</bdi>
          </span>
        )}
      </Link>
    );
  };

  return (
    <div className="flex min-h-screen">
      {/* Logical `start-0` / `ms-64`, not `left-0` / `ml-64`: in RTL the sidebar
          belongs on the right, and physical offsets put it on the wrong edge
          while leaving a 16rem gutter on the other one (FR-015). */}
      <aside
        id="panel-nav"
        /* ⚠️ A FLEX COLUMN, and the footer is a CHILD of it — not an
           `absolute bottom-0` panel over a scrolling list. Positioned, it sat
           on top of the last nav entries on a short viewport and INTERCEPTED
           THEIR CLICKS: the admin links were visible, focusable and unreachable
           on a phone, which is a link that does not exist wearing the costume of
           one. Found by e2e on the 360×780 project. */
        className={`fixed inset-y-0 start-0 z-20 flex w-64 flex-col border-e border-line bg-surface-raised md:flex ${navOpen ? "flex" : "hidden"}`}
      >
        <div className="flex h-16 shrink-0 items-center px-6">
          <Link
            href="/dashboard"
            className="rounded text-xl font-extrabold text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {PLATFORM_NAME}
          </Link>
        </div>
        {/* The one thing that scrolls. Everything else keeps its height, so a
            long nav never pushes the account panel off the screen. */}
        <nav aria-label="التنقّل الرئيسي" className="flex-1 overflow-y-auto px-3 py-4">
          {allowed(mainNav).map(renderItem)}
          {/* ⚠️ The heading is hidden with its list, not left standing over an
              empty box. A section title with nothing under it reads as content
              that failed to load. */}
          {allowed(adminNav).length > 0 && (
            <div className="mb-1 mt-4 border-t border-line pt-4">
              <p className="mb-2 px-3 text-xs font-semibold tracking-wide text-ink-muted">
                الإدارة
              </p>
              {allowed(adminNav).map(renderItem)}
            </div>
          )}
          {/* ⚠️ Per item, no longer on `is_super_admin`. That flag had the bug
              running the other way too: a platform finance officer holds
              `billing.audit.view` through `platform_staff` and never saw the
              link, because they are not a super admin. */}
          {allowed(platformNav).length > 0 && (
            <div className="mb-1 mt-4 border-t border-line pt-4">
              <p className="mb-2 px-3 text-xs font-semibold tracking-wide text-ink-muted">
                المنصّة
              </p>
              {allowed(platformNav).map(renderItem)}
            </div>
          )}
        </nav>
        <div className="shrink-0 border-t border-line bg-surface-raised p-4">
          <div className="mb-3 flex items-center gap-3">
            <div
              aria-hidden="true"
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-medium text-white"
            >
              {user.first_name.charAt(0)}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-ink">{user.name}</p>
              <p className="truncate text-xs text-ink-muted">
                {/* Latin inside Arabic: <bdi> keeps the address from being
                    reordered around the surrounding RTL run (FR-005). */}
                <bdi>{user.email}</bdi>
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => {
              logout();
              router.push("/login");
            }}
            className="flex w-full items-center justify-center gap-2 rounded-lg border border-line py-2 text-sm text-ink transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            <LogoutIcon />
            تسجيل الخروج
          </button>
        </div>
      </aside>

      <div className="flex-1 md:ms-64">
        <header className="sticky top-0 z-10 flex h-16 items-center justify-between gap-4 border-b border-line bg-surface-raised px-6">
          <div className="flex min-w-0 items-center gap-3">
            <button
              type="button"
              onClick={() => setNavOpen((open) => !open)}
              aria-expanded={navOpen}
              aria-controls="panel-nav"
              aria-label={navOpen ? "إغلاق التنقّل" : "فتح التنقّل"}
              className="rounded-lg p-1 text-ink hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:hidden"
            >
              {navOpen ? <CloseIcon /> : <MenuIcon />}
            </button>

            <h1 className="truncate text-lg font-semibold text-ink">
              {allNav.find((i) => pathname.startsWith(i.href))?.label ?? "لوحة التحكم"}
            </h1>
          </div>
          <div className="flex items-center gap-1">
            <NotificationBell />
            <ThemeToggle />
          </div>
        </header>
        <main id="main" className="p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
