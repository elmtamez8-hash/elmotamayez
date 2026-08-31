"use client";

import {
  BookIcon,
  CloseIcon,
  DocumentIcon,
  HomeIcon,
  InfoIcon,
  MenuIcon,
  TagIcon,
  UsersIcon,
} from "@/components/icons";
import Link from "next/link";
import { useState } from "react";
import { usePlatformName } from "@/lib/platform-context";
import { BrandMarkDecorative } from "@/components/ui/BrandMark";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { NotificationBell } from "@/components/app/NotificationBell";
import { panelPathFor, useAuth } from "@/lib/auth-context";

/**
 * ⚠️ THE ICONS ARE THE FOOTER'S, ROUTE FOR ROUTE. Five of these six links appear
 * in `SiteFooter` under «المنصة» carrying `UsersIcon`, `BookIcon`, `DocumentIcon`,
 * `TagIcon` and `InfoIcon`; picking a fresh one here would give the same
 * destination two pictures on one page, and a reader who learns a mark in the
 * footer has to learn it again at the top. `/` is the only route with no footer
 * row, and `HomeIcon` is what the panel's own sidebar already uses for it.
 */
const NAV = [
  { href: "/", label: "الرئيسية", Icon: HomeIcon },
  { href: "/teachers", label: "المدرسون", Icon: UsersIcon },
  { href: "/courses", label: "الكورسات", Icon: BookIcon },
  { href: "/blog", label: "المدوّنة", Icon: DocumentIcon },
  { href: "/pricing", label: "الأسعار", Icon: TagIcon },
  { href: "/about", label: "عن المنصة", Icon: InfoIcon },
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
  const platform = usePlatformName();

  return (
    <header className="sticky top-0 z-40 border-b border-line bg-surface/95 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6">
        {/* The mark carries the name, so the name is not repeated beside it —
            a wordmark plus its own text set twice is the tell of a logo nobody
            trusts to be legible. The accessible name still says it. */}
        <Link href="/" aria-label={platform} className="shrink-0">
          <BrandMarkDecorative size="lg" />
        </Link>

        <nav aria-label="التنقّل الرئيسي" className="hidden flex-1 lg:block">
          <ul className="flex items-center gap-1">
            {NAV.map(({ href, label, Icon }) => (
              <li key={href}>
                {/*
                 * The underline is `link-underline` — the footer's, from
                 * `globals.css`, not a second `hover:border-b` written here. It
                 * grows from the inline START, so it runs right-to-left in Arabic
                 * without a branch, and it replaces the pill background that used
                 * to fill on hover: a filled pill AND a rule under the words is
                 * two answers to «you are pointing at this».
                 *
                 * The icon nudges toward the label exactly as the footer's does —
                 * one cue that the icon and the words are one target rather than
                 * two — and `motion-reduce:` cancels both the shift and the
                 * transition for a reader who asked for that.
                 */}
                <Link
                  href={href}
                  className="group inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-ink transition duration-200 hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
                >
                  <Icon className="h-4 w-4 shrink-0 transition duration-200 group-hover:-translate-x-0.5 group-hover:text-primary-ink motion-reduce:transition-none motion-reduce:group-hover:translate-x-0 rtl:group-hover:translate-x-0.5" />
                  <span className="link-underline">{label}</span>
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
            {NAV.map(({ href, label, Icon }) => (
              <li key={href}>
                {/* The phone menu keeps the filled row and NOT the underline: a
                    tap has no hover to reveal one, and a 44px row is a target the
                    background states better than a 1.5px rule does. The icon and
                    the weight are the same, so it reads as the same navigation. */}
                <Link
                  href={href}
                  onClick={() => setOpen(false)}
                  className="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-bold text-ink hover:bg-primary-soft"
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  {label}
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
