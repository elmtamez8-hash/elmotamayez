"use client";

import Link from "next/link";
import { useState } from "react";
import { PLATFORM_NAME } from "@/lib/platform";
import { ThemeToggle } from "./ThemeToggle";

const NAV = [
  { href: "/", label: "الرئيسية" },
  { href: "/teachers", label: "المدرسون" },
  { href: "/courses", label: "الكورسات" },
  { href: "/pricing", label: "الأسعار" },
  { href: "/about", label: "عن المنصة" },
];

export function SiteHeader() {
  const [open, setOpen] = useState(false);

  return (
    <header className="sticky top-0 z-40 border-b border-line bg-surface/95 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6">
        <Link href="/" className="text-xl font-extrabold text-primary">
          {PLATFORM_NAME}
        </Link>

        <nav aria-label="التنقّل الرئيسي" className="hidden flex-1 lg:block">
          <ul className="flex items-center gap-1">
            {NAV.map((item) => (
              <li key={item.href}>
                <Link
                  href={item.href}
                  className="rounded-lg px-3 py-2 text-sm font-medium text-ink transition hover:bg-primary-soft hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <div className="mr-auto flex items-center gap-2">
          <ThemeToggle />
          <Link
            href="/login"
            className="hidden rounded-xl px-4 py-2 text-sm font-semibold text-ink transition hover:bg-primary-soft sm:block"
          >
            تسجيل دخول
          </Link>
          <Link
            href="/signup/student"
            className="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            إنشاء حساب
          </Link>

          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            className="rounded-lg p-2 text-ink lg:hidden"
            aria-expanded={open}
            aria-controls="mobile-nav"
            aria-label="قائمة التنقّل"
          >
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
              <path strokeLinecap="round" d={open ? "M6 18L18 6M6 6l12 12" : "M4 7h16M4 12h16M4 17h16"} />
            </svg>
          </button>
        </div>
      </div>

      {open && (
        <nav id="mobile-nav" aria-label="التنقّل الرئيسي" className="border-t border-line lg:hidden">
          <ul className="mx-auto max-w-7xl px-4 py-2 sm:px-6">
            {NAV.map((item) => (
              <li key={item.href}>
                <Link
                  href={item.href}
                  onClick={() => setOpen(false)}
                  className="block rounded-lg px-3 py-3 text-sm font-medium text-ink hover:bg-primary-soft"
                >
                  {item.label}
                </Link>
              </li>
            ))}
            <li>
              <Link
                href="/login"
                onClick={() => setOpen(false)}
                className="block rounded-lg px-3 py-3 text-sm font-medium text-ink hover:bg-primary-soft sm:hidden"
              >
                تسجيل دخول
              </Link>
            </li>
          </ul>
        </nav>
      )}
    </header>
  );
}
