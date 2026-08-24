"use client";

import { CloseIcon, MenuIcon } from "@/components/icons";
import Link from "next/link";
import { useState } from "react";
import { PLATFORM_NAME } from "@/lib/platform";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { NotificationBell } from "@/components/app/NotificationBell";
import { panelPathFor, useAuth } from "@/lib/auth-context";

const NAV = [
  { href: "/", label: "الرئيسية" },
  { href: "/teachers", label: "المدرسون" },
  { href: "/courses", label: "الكورسات" },
  { href: "/pricing", label: "الأسعار" },
  { href: "/about", label: "عن المنصة" },
];

export function SiteHeader() {
  const [open, setOpen] = useState(false);
  /*
   | ⚠️ THE HEADER HAS TO ASK. A student is sent to the marketplace when they
   | sign in (homePathFor), so this header is the first thing they see AFTER
   | being admitted — and while it was static it went on offering "sign in" to
   | somebody who just had, with no link into the product anywhere on the page.
   |
   | `user` is null on the server and on the first client paint, so the guest
   | markup is what prerenders and there is no hydration mismatch; the swap
   | happens once the token in localStorage has been exchanged for a profile.
   */
  const { user } = useAuth();

  return (
    <header className="sticky top-0 z-40 border-b border-line bg-surface/95 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6">
        {/* The mark carries the name, so the name is not repeated beside it —
            a wordmark plus its own text set twice is the tell of a logo nobody
            trusts to be legible. The accessible name still says it. */}
        <Link href="/" aria-label={PLATFORM_NAME} className="shrink-0">
          <span className="wordmark h-10" aria-hidden="true" />
        </Link>

        <nav aria-label="التنقّل الرئيسي" className="hidden flex-1 lg:block">
          <ul className="flex items-center gap-1">
            {NAV.map((item) => (
              <li key={item.href}>
                <Link
                  href={item.href}
                  className="rounded-lg px-3 py-2 text-sm font-medium text-ink transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <div className="ms-auto flex items-center gap-2">
          <ThemeToggle />
          {user === null ? (
            <>
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
            </>
          ) : (
            <>
              {/*
                ⚠️ THE SAME COMPONENT AS THE PANEL'S, NOT A SECOND ONE. It was
                mounted only inside `(app)/(shell)`, so a signed-in person
                browsing the marketplace — the teacher list, a profile, the
                pricing page — had no bell at all and learned about a message
                only by navigating back into the panel. It is also the request
                that discovers an ended session, so its absence here left those
                pages showing an evicted account a screen it was no longer
                entitled to for as long as the visitor stayed on them.
              */}
              <NotificationBell />
              <Link
                href={panelPathFor(user)}
                className="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                {user.first_name}
              </Link>
            </>
          )}

          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            className="rounded-lg p-2 text-ink lg:hidden"
            aria-expanded={open}
            aria-controls="mobile-nav"
            aria-label="قائمة التنقّل"
          >
            {open ? <CloseIcon /> : <MenuIcon />}
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
              {/* The phone menu answers the same question as the bar above it.
                  Left saying "sign in", it is the only route a signed-in student
                  on a phone can see — back to the screen they came from. */}
              <Link
                href={user === null ? "/login" : panelPathFor(user)}
                onClick={() => setOpen(false)}
                className="block rounded-lg px-3 py-3 text-sm font-medium text-ink hover:bg-primary-soft sm:hidden"
              >
                {user === null ? "تسجيل دخول" : "حسابي"}
              </Link>
            </li>
          </ul>
        </nav>
      )}
    </header>
  );
}
