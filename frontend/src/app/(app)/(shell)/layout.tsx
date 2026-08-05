"use client";

import { useAuth } from "@/lib/auth-context";
import { useRouter, usePathname } from "next/navigation";
import { useEffect, type ReactNode, type ComponentType } from "react";
import Link from "next/link";
import { PLATFORM_NAME } from "@/lib/platform";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import {
  CertificateIcon,
  CoursesIcon,
  HomeIcon,
  ExamIcon,
  LearningIcon,
  LogoutIcon,
  MembersIcon,
  OrdersIcon,
  SettingsIcon,
  WorkspaceIcon,
  type IconProps,
} from "@/components/icons";

type NavItem = { href: string; label: string; Icon: ComponentType<IconProps> };

const mainNav: NavItem[] = [
  { href: "/dashboard", label: "لوحة التحكم", Icon: HomeIcon },
  // /courses is the public marketplace listing; course management lives under
  // /manage so the two do not resolve to the same route.
  { href: "/manage/courses", label: "الكورسات", Icon: CoursesIcon },
  { href: "/enrollments", label: "تعلّمي", Icon: LearningIcon },
  { href: "/exams", label: "الاختبارات", Icon: ExamIcon },
  { href: "/certificates", label: "الشهادات", Icon: CertificateIcon },
  { href: "/orders", label: "الطلبات", Icon: OrdersIcon },
];

const adminNav: NavItem[] = [
  { href: "/workspaces", label: "مساحات العمل", Icon: WorkspaceIcon },
  { href: "/members", label: "الأعضاء", Icon: MembersIcon },
  { href: "/settings", label: "الإعدادات", Icon: SettingsIcon },
];

const allNav = [...mainNav, ...adminNav];

export default function ShellLayout({ children }: { children: ReactNode }) {
  const { user, loading, logout } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !user) {
      router.push("/login");
    }
  }, [user, loading, router]);

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
      <aside className="fixed inset-y-0 start-0 z-20 w-64 overflow-y-auto border-e border-line bg-surface-raised">
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

      <div className="ms-64 flex-1">
        <header className="sticky top-0 z-10 flex h-16 items-center justify-between gap-4 border-b border-line bg-surface-raised px-6">
          <h1 className="text-lg font-semibold text-ink">
            {allNav.find((i) => pathname.startsWith(i.href))?.label ?? "لوحة التحكم"}
          </h1>
          <ThemeToggle />
        </header>
        <main id="main" className="p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
