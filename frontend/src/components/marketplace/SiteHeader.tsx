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
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { usePlatformName } from "@/lib/platform-context";
import { BrandMarkDecorative } from "@/components/ui/BrandMark";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { NotificationBell } from "@/components/app/NotificationBell";
import { panelPathFor, useAuth } from "@/lib/auth-context";
import { quickAccessFor } from "@/lib/panel-nav";
import { AccountMenu } from "@/components/app/AccountMenu";

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

/**
 * Is `href` the page being read?
 *
 * ⚠️ `/` IS EXACT AND EVERY OTHER ROUTE IS A PREFIX, and the two cannot be one
 * rule. `startsWith("/")` is true of every path in the product, so the naive form
 * marks «الرئيسية» as current on all six screens — six lit links say the same
 * thing as none. The prefix half is what keeps «المدرسون» lit on a teacher's own
 * page (`/teachers/{uuid}`), which is where a reader is most likely to have
 * forgotten which section they are in.
 *
 * The boundary is `href + "/"`, never a bare `startsWith`: without it `/course`
 * would light `/courses`, and a route added tomorrow whose name merely begins
 * with an existing one lights the wrong row with nothing failing.
 */
export function isCurrentPath(pathname: string, href: string): boolean {
  return href === "/" ? pathname === "/" : pathname === href || pathname.startsWith(`${href}/`);
}

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
  const { user, logout } = useAuth();
  const router = useRouter();
  const platform = usePlatformName();
  const pathname = usePathname();

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
            {NAV.map(({ href, label, Icon }) => {
              const current = isCurrentPath(pathname, href);

              return (
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
                {/*
                 * ⚠️ THE CURRENT PAGE IS MARKED THREE WAYS, AND ONLY ONE OF THEM
                 * IS A COLOUR. `aria-current="page"` is the fact — it is what a
                 * screen reader announces and what the persistent underline in
                 * `globals.css` is keyed on, so the mark and its meaning cannot
                 * drift apart. `text-primary-ink` is the brand ink and NOT
                 * `text-primary`: the second is the maroon a white foreground is
                 * earned against and it never lightens in the dark theme, while
                 * `primary-ink` is #8a1538 on white and #e9a0b2 on the dark
                 * ground — the whole reason that token exists apart from it.
                 * And the weight steps up rather than turning on, because every
                 * row here was already `font-bold`.
                 */}
                <Link
                  href={href}
                  aria-current={current ? "page" : undefined}
                  className={`group inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition duration-200 hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none ${
                    current ? "font-extrabold text-primary-ink" : "font-bold text-ink"
                  }`}
                >
                  <Icon
                    className={`h-4 w-4 shrink-0 transition duration-200 group-hover:-translate-x-0.5 group-hover:text-primary-ink motion-reduce:transition-none motion-reduce:group-hover:translate-x-0 rtl:group-hover:translate-x-0.5 ${
                      current ? "text-primary-ink" : ""
                    }`}
                  />
                  <span className="link-underline">{label}</span>
                </Link>
              </li>
              );
            })}
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
              {/*
                ⚠️ **قائمةٌ لا زرّ، ونفسُ المكوّنِ الذي في اللوحة** — طلبُ
                ٢٠٢٦-٠٩-٠٦: «عايزه يظهر في كامل الصفحات وليس في داشبورد فقط».
                كانَ هنا زرٌّ واحدٌ يحملُ الاسمَ الأوّلَ ويقفزُ إلى اللوحة، ولا
                صورةَ ولا إعداداتٍ ولا خروج — **وهذه هي الصفحاتُ التي يعيشُ فيها
                الطالبُ فعلاً**: `homePathFor` يُنزِلُه على `‎/teachers`، وتصفّحُ
                الكورساتِ والمدرّسينَ كلُّه عامّ. فقائمةُ الحسابِ في اللوحةِ وحدَها
                كانت قائمةً في المكانِ الذي يمرُّ به أقلَّ ما يمرّ.
                والروابطُ السريعةُ من `quickAccessFor` — الحارسُ نفسُه الذي يرسمُ
                الشريطَ الجانبيّ — لا من قائمةٍ ثانيةٍ مكتوبةٍ هنا.
              */}
              <AccountMenu
                name={user.name}
                firstName={user.first_name}
                photoUrl={user.photo_url ?? null}
                quickLinks={quickAccessFor(user)}
                panelHref={panelPathFor(user)}
                onLogout={() => {
                  void logout();
                  router.push("/login");
                }}
              />
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
            {NAV.map(({ href, label, Icon }) => {
              const current = isCurrentPath(pathname, href);

              return (
              <li key={href}>
                {/* The phone menu keeps the filled row and NOT the underline: a
                    tap has no hover to reveal one, and a 44px row is a target the
                    background states better than a 1.5px rule does. The icon and
                    the weight are the same, so it reads as the same navigation.

                    So the current row wears the fill PERMANENTLY rather than
                    borrowing the hover state — on a touch screen there is no
                    hover to distinguish it from, which is exactly why the bar
                    above uses a rule here and a background there. */}
                <Link
                  href={href}
                  onClick={() => setOpen(false)}
                  aria-current={current ? "page" : undefined}
                  className={`flex items-center gap-3 rounded-lg px-3 py-3 text-sm hover:bg-primary-soft ${
                    current ? "bg-primary-soft font-extrabold text-primary-ink" : "font-bold text-ink"
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  {label}
                </Link>
              </li>
              );
            })}
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
