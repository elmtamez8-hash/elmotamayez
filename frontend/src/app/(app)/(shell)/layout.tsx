"use client";

import { useAuth } from "@/lib/auth-context";
import { useRouter, usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode, type ComponentType } from "react";
import Link from "next/link";
import { usePlatformName } from "@/lib/platform-context";
import { BrandMarkDecorative } from "@/components/ui/BrandMark";
import { Alert } from "@/components/ui/Alert";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { AccountMenu } from "@/components/app/AccountMenu";
import { Avatar } from "@/components/ui/Avatar";
import { NotificationBell } from "@/components/app/NotificationBell";
import { ServiceWorkerRegistrar } from "@/components/app/ServiceWorkerRegistrar";
import { P, can, refusedBy } from "@/lib/permissions";
import {
  adminNav,
  allNav,
  allowedNav,
  mainNav,
  platformNav,
  quickAccessFor,
  type NavItem,
} from "@/lib/panel-nav";
import { TONE_CLASSES } from "@/lib/labels";
import { grading } from "@/lib/grading";
import {
  ChevronEndIcon,
  ChevronStartIcon,
  CloseIcon,
  LogoutIcon,
  SiteIcon,
  MenuIcon,
} from "@/components/icons";



/** Whether the reader last chose the narrow rail. Per browser, per person. */
const NAV_COLLAPSED_KEY = "nav:collapsed";


export default function ShellLayout({ children }: { children: ReactNode }) {
  const { user, loading, logout } = useAuth();
  // The product's name, for the mark's accessible label and its tooltip. The
  // logo itself carries no text, so this is the only thing announced.
  const platform = usePlatformName();
  const router = useRouter();
  const pathname = usePathname();

  // Below `md` the 16rem sidebar is wider than half a phone and sits over the
  // page, so it is a drawer there and permanent from `md` up. Without this the
  // panel is not merely cramped on a phone — the nav intercepts every click
  // meant for the content behind it.
  const [navOpen, setNavOpen] = useState(false);
  const [pendingGrading, setPendingGrading] = useState(0);

  /*
    ⚠️ THE RAIL IS A SECOND STATE, NOT THE DRAWER AT ANOTHER WIDTH. On a phone the
    nav sits OVER the page and the question is «is it open»; from `md` up it sits
    BESIDE the page and the question is «how much room does it take». One flag for
    both would mean closing the drawer on a phone also collapsed the desktop rail
    the next time the reader opened a laptop — two answers to two different
    questions, stored in one box.

    Read from `localStorage` after mount, never during render: the server has no
    such thing, and reading it in the initial state is a hydration mismatch that
    React resolves by silently keeping the server's answer.
  */
  const [collapsed, setCollapsed] = useState(false);

  useEffect(() => {
    try {
      setCollapsed(window.localStorage.getItem(NAV_COLLAPSED_KEY) === "1");
    } catch {
      // A private window, or site data blocked. The default is the expanded nav,
      // which is the state that hides nothing.
    }
  }, []);

  const toggleCollapsed = () => {
    setCollapsed((current) => {
      const next = !current;

      try {
        window.localStorage.setItem(NAV_COLLAPSED_KEY, next ? "1" : "0");
      } catch {
        // Not being able to remember the choice must not stop them making it.
      }

      return next;
    });
  };

  /*
   * ⚠️ THE ADDRESS TRAVELS WITH THEM (027 · FR-005). This used to push a bare
   * `/login`, which was harmless while every screen behind the shell was one a
   * signed-in person had navigated to from inside — and became a silent loss the
   * moment `/subscribe` arrived. A visitor who presses «اشترك في هذه المجموعة»
   * on a public course page lands here, is bounced, signs in, and is deposited
   * on their dashboard with the group they chose forgotten. That is exactly the
   * «تعقيد» this feature exists to remove.
   *
   * ⚠️ AND THE QUERY IS PART OF IT. `/subscribe` carries its whole state in the
   * address (`?course=…&cohort=…`), so a redirect that kept only the pathname
   * would return them to a screen with nothing chosen.
   *
   * ⚠️ READ FROM `window.location`, NOT `useSearchParams()`. That hook forces a
   * Suspense boundary at prerender time, and a build-time route error in this
   * tree is a 500 on the WHOLE application rather than on one page. This effect
   * runs only in the browser, after mount, where the location is simply there.
   */
  useEffect(() => {
    if (!loading && !user) {
      const intended = `${pathname}${window.location.search}`;

      router.push(`/login?next=${encodeURIComponent(intended)}`);
    }
  }, [user, loading, router, pathname]);

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

  /*
   * ⚠️ TWO GATES, AND THEY ANSWER DIFFERENT QUESTIONS IN DIFFERENT DIRECTIONS.
   * `can()` asks «may you», which hides the teacher's tools from the student.
   * `audience` asks «is this yours», which hides the student's screens from the
   * teacher — and there is no permission that could have done it, because the
   * student holds none to check. It names the three audiences rather than
   * carrying a boolean: a guardian is not a student, and the boolean it used to
   * be handed them fifteen screens that read the caller's own empty rows.
   *
   * ⚠️ IT IS A FILTER ON WHAT IS OFFERED, NEVER A GUARD. The routes stay open,
   * exactly as this file's own rule has always had it: the server decides. A
   * teacher who types `/shop` still gets the page, and it is empty — which is
   * the honest answer, not a refusal.
   */
  // الحارسُ نفسُه الذي تقرؤُه ترويسةُ الموقعِ العامّة — انظر `lib/panel-nav`.
  const allowed = (items: NavItem[]) => allowedNav(items, user);

  /*
   * أماكن العمل: العدد يقرّر اللافتة (مواصفة ٠٢٥ · FR-014 · FR-014أ · FR-025).
   *
   * ⚠️ ثلاثة أجوبة لا اثنان، والوسطُ هو المقصود بالبند. «مكان عملي» صيغةُ مفردٍ
   * من جمع، وهي تُبقي في ذهن القارئ أنّ ثمّة أماكنَ أخرى — وهو بالضبط ما تُلغيه
   * هذه المواصفة. فحين يكون واحدًا يُعرَض **اسمُه هو**، المشتقُّ من اسم المدرّس.
   *
   * ⚠️ ويُقرأ من `useAuth()` بلا جلبٍ ثانٍ: الحقل يصل مع `me()` أصلًا.
   */
  const places = user?.workspaces ?? [];

  const placeLabelled = (items: NavItem[]) =>
    items.flatMap((item) => {
      if (item.href !== "/workspaces") return [item];
      if (places.length === 0) return [];

      return [{ ...item, label: places.length === 1 ? places[0].name : item.label }];
    });

  /*
   * ⚠️ **`placeLabelled` كانت على `mainNav` وحدَها، و«أماكن عملي» في `adminNav`.**
   * فالشرطُ الذي يُسقِطُ البندَ عند `places.length === 0` لم يكن يمرُّ عليه أصلاً:
   * وليُّ الأمرِ والطالبُ — وكلاهما عضوٌ في لا مكانَ عمل — كانا يريانِ عنوانَ
   * «الإدارة» وتحتَه بندٌ واحدٌ يفتحُ قائمةً فارغة. والنصفُ الآخرُ من العطلِ أنّ
   * تسميةَ المكانِ الواحدِ باسمِه (٠٢٥ · `FR-014`) لم تكن تُطبَّقُ قطّ.
   *
   * ⚠️ والحسابُ مرّةً واحدةً لا مرّتَين: العنوانُ يُشترَطُ بالقائمةِ **بعدَ**
   * الترشيح، وإلّا وقفَ «الإدارة» فوقَ صندوقٍ فارغ.
   */
  const adminItems = placeLabelled(allowed(adminNav));

  const refused = refusedBy(allNav, pathname, user);

  const renderItem = ({ href, label, Icon, badge }: NavItem) => {
    const active = pathname === href || pathname.startsWith(href + "/");
    const showBadge = badge === "grading" && pendingGrading > 0;

    return (
      <Link
        key={href}
        href={href}
        aria-current={active ? "page" : undefined}
        /*
          ⚠️ `title` ONLY WHEN THE LABEL IS HIDDEN. A tooltip repeating text that
          is already on screen is a second copy for a screen reader to announce;
          on the rail it is the only way to learn what the icon means with a
          mouse. The accessible name comes from the `sr-only` span below either
          way, so the link is never an unlabelled glyph.
        */
        title={collapsed ? label : undefined}
        /*
          ⚠️ EVERY COLLAPSED STYLE IS AN `md:` VARIANT, WITHOUT EXCEPTION. The rail
          is a desktop state and the drawer is not — but `collapsed` is remembered
          per BROWSER, so an un-gated class means the reader who narrowed the nav
          on their laptop opens the drawer on their phone and finds a full-width
          panel of unlabelled icons. That is the report this whole change answers,
          re-manufactured one breakpoint lower.
        */
        className={`mb-1 flex items-center gap-3 rounded-lg py-2.5 text-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary px-3 ${
          collapsed ? "md:justify-center md:px-2" : ""
        } ${
          active
            ? "bg-primary font-semibold text-white"
            : "text-ink hover:bg-primary-soft hover:text-primary-ink"
        }`}
      >
        <span className="relative shrink-0">
          <Icon className="h-5 w-5" />

          {/*
            ⚠️ ON THE RAIL THE COUNT BECOMES A DOT ON THE ICON, and it must not
            disappear. The number is the whole reason «لوحة التصحيح» earns a place
            in this list — a paper waiting to be marked is a student waiting for a
            result — so collapsing the nav may take the digits and may not take
            the fact that something is waiting.
          */}
          {showBadge && collapsed && (
            <span
              aria-hidden
              className="absolute -end-0.5 -top-0.5 hidden h-2 w-2 rounded-full bg-accent ring-2 ring-surface-raised md:block"
            />
          )}
        </span>

        <span className={`flex-1 ${collapsed ? "md:sr-only" : ""}`}>{label}</span>

        {showBadge && (
          <span
            className={`rounded-full px-2 py-0.5 text-xs font-semibold ${collapsed ? "md:hidden" : ""} ${
              active ? "bg-white/20 text-white" : TONE_CLASSES.warning
            }`}
          >
            <bdi>{pendingGrading}</bdi>
          </span>
        )}

        {/* On the rail the visible count is gone; this keeps it in the accessible
            name rather than leaving «لوحة التصحيح» with a silent dot. Harmless at
            full width, where the badge above already says it visibly. */}
        {showBadge && collapsed && <span className="sr-only">{`${pendingGrading} بانتظار التصحيح`}</span>}
      </Link>
    );
  };

  return (
    <div className="flex min-h-screen">
      {/* Renders nothing. Here rather than in the root layout because the worker
          exists for the signed-in application — a visitor reading the
          marketplace has nothing to cache and nothing to be pushed. */}
      <ServiceWorkerRegistrar />
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
        /*
          ⚠️ THE DRAWER IS ALWAYS FULL WIDTH; ONLY THE DESKTOP RAIL NARROWS. A
          16rem panel is wider than half a phone and sits OVER the page there, so
          a «space-saving» 4rem version of it would save space nobody was using
          and hide the labels on the one screen with room for them under a finger.
          The rail is a `md:` question, and the drawer is not.

          ⚠️ AND `transition-[width]`, NOT `transition-all`. The panel is
          `fixed inset-y-0` with a scrolling child; animating every property makes
          the browser re-layout that child on each frame of the collapse.
        */
        className={`fixed inset-y-0 start-0 z-20 ${navOpen ? "flex" : "hidden"} w-64 flex-col border-e border-line bg-surface-raised transition-[width] duration-200 md:flex ${collapsed ? "md:w-16" : "md:w-64"}`}
      >
        <div className={`flex h-16 shrink-0 items-center px-6 ${collapsed ? "md:justify-center md:px-0" : ""}`}>
          <Link
            href="/dashboard"
            title={platform}
            /*
              ⚠️ THE ACCESSIBLE NAME IS SET AT EVERY WIDTH NOW, NOT ONLY ON THE
              RAIL. The mark is a masked background on an empty span, so there is
              no text node left for a screen reader to read — without this the
              link would be announced as «رابط» and nothing else. It used to be
              conditional because the expanded state carried the word itself.
            */
            aria-label={platform}
            className="flex items-center rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {/*
              ⚠️ THE SAME MARK THE PUBLIC HEADER AND `/admin` PAINT, from one
              asset. `.wordmark` is a `mask-image` over `background-color`, so the
              maroon comes from the token on light and the warm white on dark —
              one file for both themes, and nothing to keep in step with a second
              export. Its `aspect-ratio` is 941/789, so at `h-9` it is ~43px wide
              and still fits the 4rem rail: the old first-letter fallback for the
              collapsed state has nothing left to do.
            */}
            <BrandMarkDecorative size={collapsed ? "sm" : "md"} />
          </Link>
        </div>
        {/* The one thing that scrolls. Everything else keeps its height, so a
            long nav never pushes the account panel off the screen. */}
        <nav aria-label="التنقّل الرئيسي" className={`flex-1 overflow-y-auto py-4 px-3 ${collapsed ? "md:px-2" : ""}`}>
          {placeLabelled(allowed(mainNav)).map(renderItem)}
          {/* ⚠️ The heading is hidden with its list, not left standing over an
              empty box. A section title with nothing under it reads as content
              that failed to load. */}
          {adminItems.length > 0 && (
            <div className="mb-1 mt-4 border-t border-line pt-4">
              {/* ⚠️ Hidden on the rail rather than truncated. «الإدارة» clipped to
                  two letters over a column of icons is noise where the border
                  above it already says «a new group starts here». */}
              <p className={`mb-2 px-3 text-xs font-semibold tracking-wide text-ink-muted ${collapsed ? "md:sr-only" : ""}`}>
                الإدارة
              </p>
              {adminItems.map(renderItem)}
            </div>
          )}
          {/* ⚠️ Per item, no longer on `is_super_admin`. That flag had the bug
              running the other way too: a platform finance officer holds
              `billing.audit.view` through `platform_staff` and never saw the
              link, because they are not a super admin. */}
          {allowed(platformNav).length > 0 && (
            <div className="mb-1 mt-4 border-t border-line pt-4">
              <p className={`mb-2 px-3 text-xs font-semibold tracking-wide text-ink-muted ${collapsed ? "md:sr-only" : ""}`}>
                المنصّة
              </p>
              {allowed(platformNav).map(renderItem)}
            </div>
          )}
        </nav>
        <div className={`shrink-0 border-t border-line bg-surface-raised p-4 ${collapsed ? "md:p-2" : ""}`}>
          <div className={`mb-3 flex items-center gap-3 ${collapsed ? "md:justify-center" : ""}`}>
            {/*
              ⚠️ الصورةُ الحقيقيّةُ، وكانَ هنا الحرفُ الأوّلُ دائماً. `photo_url`
              يصلُ مع `me()` منذُ أن صارَ للعمودِ كاتب، ولم يقرأْه هذا الموضعُ —
              فمن رفعَ صورتَه رآها في بطاقةِ الإعداداتِ وحدَها وبقيَ الحرفُ في كلِّ
              صفحة. بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-٠٦.
              و`Avatar` من الطقمِ لا دائرةٌ مكتوبةٌ هنا: الاحتياطُ نفسُه بالألوانِ
              نفسِها في الشريطِ والترويسةِ والبطاقة.
            */}
            <div title={collapsed ? user.name : undefined}>
              <Avatar url={user.photo_url ?? null} name={user.first_name} size="sm" />
            </div>

            {/* ⚠️ REMOVED FROM THE RAIL RATHER THAN TRUNCATED. An address cut to
                «ahm…» in a 4rem column is not a shorter address, it is an
                unreadable one — and the initial above already says whose account
                this is. */}
            <div className={`min-w-0 flex-1 ${collapsed ? "md:hidden" : ""}`}>
              <p className="truncate text-sm font-medium text-ink">{user.name}</p>
              <p className="truncate text-xs text-ink-muted">
                {/* Latin inside Arabic: <bdi> keeps the address from being
                    reordered around the surrounding RTL run (FR-005). */}
                <bdi>{user.email}</bdi>
              </p>
            </div>
          </div>
          {/*
            ⚠️ THE WAY BACK TO THE PUBLIC SITE, AND THERE WAS NONE. Once signed
            in, every link in this shell points further INTO the panel — so the
            marketplace, the teacher pages and the policies the footer links from
            every public page were reachable only by editing the address bar. The
            logo above goes to `/dashboard` (a signed-in person's home is their
            own screen), which is exactly why the home page needs a link of its
            own rather than borrowing that one.

            Placed in the account block rather than in `mainNav`: everything in
            that list is a screen of this product, and a permission-filtered list
            is the wrong place for a link every account holds.
          */}
          <Link
            href="/"
            title={collapsed ? "الصفحة الرئيسية" : undefined}
            className="mb-2 flex w-full items-center justify-center gap-2 rounded-lg border border-line py-2 text-sm text-ink transition-colors hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            <SiteIcon />
            <span className={collapsed ? "md:sr-only" : ""}>الصفحة الرئيسية</span>
          </Link>
          <button
            type="button"
            onClick={() => {
              logout();
              router.push("/login");
            }}
            title={collapsed ? "تسجيل الخروج" : undefined}
            className="flex w-full items-center justify-center gap-2 rounded-lg border border-line py-2 text-sm text-ink transition-colors hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            <LogoutIcon />
            <span className={collapsed ? "md:sr-only" : ""}>تسجيل الخروج</span>
          </button>
        </div>
      </aside>

      {/* The offset tracks the panel's width, and animates with it — a content
          column that jumps to its new margin while the nav is still sliding is
          two elements disagreeing about where the edge is. */}
      <div className={`flex-1 transition-[margin] duration-200 ${collapsed ? "md:ms-16" : "md:ms-64"}`}>
        {/*
          ⚠️ THE BAR IS FULL-BLEED AND ITS CONTENTS ARE NOT. The rule under it has
          to reach both edges — a border that stops short reads as a card — while
          the title has to start where the content below it starts. Capped only on
          the inner row, with the same `max-w-7xl` the main region uses, or the
          heading sits at the viewport edge on a wide monitor while the page it
          names floats centred underneath.
        */}
        <header className="sticky top-0 z-10 border-b border-line bg-surface-raised">
          <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            {/*
              ⚠️ TWO BUTTONS, ONE POSITION, AND THAT IS THE FIX. There was a
              single `md:hidden` hamburger, so from `md` up the nav had NO control
              at all: sixteen rems of the window were spent on it on every screen,
              on every page, with no way to give them back. The two answer
              different questions — below `md` the panel is a drawer OVER the page
              («is it open»), from `md` up it is a column BESIDE the page («how
              wide»). One button doing both would need its icon, its label and its
              `aria-expanded` to mean two things at two widths.

              The desktop one is not `aria-expanded`: the nav is never hidden
              there, only narrowed, and announcing «collapsed» as «closed» tells a
              screen-reader user the links are gone when every one of them is
              still in the tree.
            */}
            <button
              type="button"
              onClick={() => setNavOpen((open) => !open)}
              aria-expanded={navOpen}
              aria-controls="panel-nav"
              aria-label={navOpen ? "إغلاق التنقّل" : "فتح التنقّل"}
              /* ⚠️ `p-2.5` MAKES IT 44px, AND IT WAS 32. A 24px icon under `p-1`
                 is a 32×32 target — measured on a 390px viewport — which is under
                 every published minimum for a finger and is the only way into the
                 navigation on a phone. The desktop twin below keeps the same
                 padding for alignment; a pointer needs far less, but two buttons
                 of different heights in one row is a wobble. */
              className="-m-1 rounded-lg p-2.5 text-ink transition-colors hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:hidden"
            >
              {navOpen ? <CloseIcon /> : <MenuIcon />}
            </button>

            <button
              type="button"
              onClick={toggleCollapsed}
              aria-controls="panel-nav"
              aria-label={collapsed ? "توسيع قائمة التنقّل" : "تصغير قائمة التنقّل"}
              title={collapsed ? "توسيع القائمة" : "تصغير القائمة"}
              className="-m-1 hidden rounded-lg p-2.5 text-ink transition-colors hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:block"
            >
              {/*
                ⚠️ DIRECTION LIVES IN THE ICON'S NAME, NEVER IN A CSS FLIP. In RTL
                the panel is on the RIGHT, so «collapse» points at the start edge
                and «expand» away from it — `ChevronStart`/`ChevronEnd` already
                carry that and a `scale-x-[-1]` on a chevron would point the wrong
                way in exactly one of the two directions the product supports.
              */}
              {/* Expanded: the press shrinks the panel toward the start edge it sits
                  on, so the arrow points there (right, in RTL). Collapsed: the
                  press grows it toward the end, so the arrow points away. */}
              {collapsed ? <ChevronEndIcon /> : <ChevronStartIcon />}
            </button>

            <h1 className="truncate text-lg font-semibold text-ink">
              {allNav.find((i) => pathname.startsWith(i.href))?.label ?? "لوحة التحكم"}
            </h1>
          </div>
          <div className="flex items-center gap-1">
            <NotificationBell />
            <ThemeToggle />
            {/*
              ⚠️ الروابطُ تُمرَّرُ مُرشَّحةً بـ`allowed()` — الحارسُ نفسُه الذي يرسمُ
              الشريطَ الجانبيّ — لا مبنيّةً داخلَ المكوّن. قائمتان تجيبانِ عن سؤالٍ
              واحدٍ تفترقانِ عندَ أوّلِ بندٍ يُضاف، وهذا المستودعُ دفعَ ثمنَ ذلك في
              `BookingEligibility` و`ListLeaderboardScopes`.
            */}
            <AccountMenu
              name={user.name}
              firstName={user.first_name}
              photoUrl={user.photo_url ?? null}
              quickLinks={quickAccessFor(user)}
              onLogout={() => {
                logout();
                router.push("/login");
              }}
            />
          </div>
          </div>
        </header>
        {/*
          ⚠️ THE READING WIDTH IS CAPPED HERE, IN ONE PLACE, AND IT WAS CAPPED
          NOWHERE. `<main>` carried padding and no maximum, so every unbounded
          page — the dashboard, the timetable, the lists added since — stretched
          to whatever the monitor was: a four-column stat grid across 2560px, and
          table rows whose eye has to travel a metre from the label to the value.
          Twenty-nine pages had each set their own `max-w-2xl`/`3xl` to escape it,
          which is the same fix written twenty-nine times and missing from the
          thirtieth.
          |
          | One container solves all of them and composes with those: a page that
          | wants a narrower column keeps its own `mx-auto max-w-2xl` and simply
          | centres inside this one. `w-full` so the cap never becomes a floor on a
          | phone, and the padding steps down on small screens — 24px of gutter on
          | a 360px viewport is 13% of it spent on nothing.
        */}
        <main id="main" className="mx-auto w-full max-w-7xl p-4 sm:p-6">
          {refused ? (
            // No heading of the screen's own above it: «أرصدة الطلاب» over a
            // refusal still tells the reader whose money this page is about.
            <Alert tone="warning" title="هذه الصفحة ليست لك">
              حسابك لا يملك صلاحية فتح هذه الصفحة.
            </Alert>
          ) : (
            children
          )}
        </main>
      </div>
    </div>
  );
}
