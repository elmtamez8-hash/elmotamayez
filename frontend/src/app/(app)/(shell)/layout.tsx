"use client";

import { useAuth } from "@/lib/auth-context";
import { useRouter, usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode, type ComponentType } from "react";
import Link from "next/link";
import { PLATFORM_NAME } from "@/lib/platform";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { NotificationBell } from "@/components/app/NotificationBell";
import {
  BellIcon,
  CertificateIcon,
  CloseIcon,
  CoursesIcon,
  CreditsIcon,
  FamilyIcon,
  HomeIcon,
  ExamIcon,
  LearningIcon,
  LogoutIcon,
  MembersIcon,
  MenuIcon,
  OrdersIcon,
  ScheduleIcon,
  SessionsIcon,
  SettingsIcon,
  SettlementIcon,
  WorkspaceIcon,
  type IconProps,
} from "@/components/icons";

type NavItem = { href: string; label: string; Icon: ComponentType<IconProps> };

const mainNav: NavItem[] = [
  { href: "/dashboard", label: "لوحة التحكم", Icon: HomeIcon },
  // /courses is the public marketplace listing; course management lives under
  // /manage so the two do not resolve to the same route.
  { href: "/manage/courses", label: "الكورسات", Icon: CoursesIcon },
  // /schedule is the student's own timetable across every teacher;
  // /manage/sessions is the teacher's calendar. Two screens, two audiences —
  // collapsing them into one route would make each show the other half nothing.
  { href: "/schedule", label: "جدولي", Icon: ScheduleIcon },
  { href: "/manage/sessions", label: "حصصي", Icon: SessionsIcon },
  // The teacher's own money. /orders is the student's side and is a different
  // question with different permissions — SETTLEMENT_STATEMENT_VIEW reaches only
  // the teacher, never their assistant.
  { href: "/manage/settlement", label: "كشف التسوية", Icon: SettlementIcon },
  { href: "/enrollments", label: "تعلّمي", Icon: LearningIcon },
  { href: "/exams", label: "الاختبارات", Icon: ExamIcon },
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
  { href: "/manage/billing/settings", label: "إعدادات الفوترة", Icon: CreditsIcon },
  { href: "/workspaces", label: "مساحات العمل", Icon: WorkspaceIcon },
  { href: "/members", label: "الأعضاء", Icon: MembersIcon },
  { href: "/settings", label: "الإعدادات", Icon: SettingsIcon },
];

const allNav = [...mainNav, ...adminNav];

export default function ShellLayout({ children }: { children: ReactNode }) {
  const { user, loading, logout } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  // Below `md` the 16rem sidebar is wider than half a phone and sits over the
  // page, so it is a drawer there and permanent from `md` up. Without this the
  // panel is not merely cramped on a phone — the nav intercepts every click
  // meant for the content behind it.
  const [navOpen, setNavOpen] = useState(false);

  useEffect(() => {
    if (!loading && !user) {
      router.push("/login");
    }
  }, [user, loading, router]);

  // Closed on every navigation. The drawer sits above the page on a phone, so
  // one left open covers the screen the link just went to.
  useEffect(() => setNavOpen(false), [pathname]);

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-ink-muted">جارٍ التحميل…</p>
      </div>
    );
  }

  if (!user) return null;

  const renderItem = ({ href, label, Icon }: NavItem) => {
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
        {label}
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
        className={`fixed inset-y-0 start-0 z-20 w-64 overflow-y-auto border-e border-line bg-surface-raised md:block ${navOpen ? "block" : "hidden"}`}
      >
        <div className="flex h-16 items-center px-6">
          <Link
            href="/dashboard"
            className="rounded text-xl font-extrabold text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {PLATFORM_NAME}
          </Link>
        </div>
        <nav aria-label="التنقّل الرئيسي" className="px-3 py-4">
          {mainNav.map(renderItem)}
          <div className="mb-1 mt-4 border-t border-line pt-4">
            <p className="mb-2 px-3 text-xs font-semibold tracking-wide text-ink-muted">
              الإدارة
            </p>
            {adminNav.map(renderItem)}
          </div>
        </nav>
        <div className="absolute inset-x-0 bottom-0 border-t border-line bg-surface-raised p-4">
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
