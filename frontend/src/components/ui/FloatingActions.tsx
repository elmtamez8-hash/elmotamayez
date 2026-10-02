"use client";

import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { ArrowUpIcon, WhatsAppIcon } from "@/components/icons";
import { useSupportWhatsapp } from "@/lib/platform-context";

const SHOW_AFTER_PX = 400;

/*
 * Where floating chrome does not belong, by the LAST segment of the path.
 *
 * ⚠️ TWO SCREENS, AND NEITHER IS ABOUT THE PIXELS IT WOULD COVER. `/room` is a
 * live lesson: the host's mute and remove controls sit along the bottom of the
 * stage, and a second fixed layer at `z-40` over a class in progress is the same
 * harm `ConfirmButton` exists to avoid — a window a teacher must deal with while
 * they are teaching. `/take` is a timed paper: a link that leaves the page
 * mid-attempt, floating over the question, on a screen whose whole design is to
 * keep the reader in it.
 *
 * A suffix match, because both routes carry a uuid — a prefix cannot name them.
 */
const NO_FLOATING_CHROME_SUFFIXES = ["/room", "/take"];

/*
 * ⚠️ AND THE CHAT, BY PREFIX, WHICH IS ABOUT THE PIXELS. `/messages` is a
 * full-height screen whose composer is pinned to the bottom edge, and the
 * WhatsApp button at `bottom-6 end-6` sat squarely on its send and «إزالة»
 * buttons — reported from production on 2026-09-28. A support link floating over
 * a message box is also a second way to «send a message» on the one screen whose
 * whole job is sending messages. The list (`/messages`) goes too: on a phone it
 * is the same full-height pane, and the button would cover its last row.
 */
const NO_FLOATING_CHROME_PREFIXES = [
  "/messages",
  // The whiteboard (spec 039) is the tab a teacher SHARES with the class: a
  // WhatsApp button on it is painted into every student's video and the recording.
  "/whiteboard",
];

/** Whether this screen keeps the bottom corner for its own controls. */
export function hidesFloatingChrome(pathname: string): boolean {
  return (
    NO_FLOATING_CHROME_SUFFIXES.some((suffix) => pathname.endsWith(suffix)) ||
    NO_FLOATING_CHROME_PREFIXES.some(
      (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
    )
  );
}

/**
 * The floating WhatsApp and back-to-top buttons.
 *
 * ⚠️ IT MOVED OUT OF `components/marketplace/`, WHICH IS MARKETPLACE-SPECIFIC BY
 * RULE. Once it mounts inside the signed-in shell as well as the public site it
 * is site chrome, not a marketplace component, and leaving it under that
 * directory is how the rule stops meaning anything.
 *
 * One component for both so they share a stacking context and the same bottom
 * offset. The teacher profile page pins a booking bar to the bottom on mobile;
 * these stand 1.5rem above whatever `StickyCtaBar` publishes as its height
 * (`--sticky-cta-height`, 0 when there is no bar or it is hidden at `lg`). It
 * was a fixed `bottom-24` below `lg` — a guess at the bar's height that sat a
 * few pixels from «احجز الآن» and floated needlessly high on every other page.
 *
 * ⚠️ THE NUMBER COMES FROM THE CONTEXT, NOT FROM A BUILD-TIME CONSTANT, AND THAT
 * IS WHY THIS BUTTON EXISTED FOR MONTHS WITHOUT EVER BEING SEEN. It read
 * `NEXT_PUBLIC_WHATSAPP_NUMBER`, which Next inlines at BUILD time and which was
 * set in no environment — so the guard below was permanently false. Nothing
 * failed, because that same guard is how an operator with no support line
 * switches the button off on purpose: the defect and the deliberate setting were
 * identical from the outside. It is a `platform_settings` row now, read at run
 * time and edited at `/admin/platform-settings`.
 */
export function FloatingActions() {
  const supportWhatsapp = useSupportWhatsapp();
  const pathname = usePathname();
  const [showTop, setShowTop] = useState(false);

  useEffect(() => {
    const onScroll = () => setShowTop(window.scrollY > SHOW_AFTER_PX);

    onScroll();
    // passive: this listener never calls preventDefault, and saying so lets the
    // browser scroll without waiting on it.
    window.addEventListener("scroll", onScroll, { passive: true });

    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const scrollToTop = () => {
    // Respect the OS setting here too. `scroll-behavior: auto !important` in the
    // reduced-motion block cannot reach an imperative call that asks for smooth.
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    window.scrollTo({ top: 0, behavior: reduced ? "auto" : "smooth" });
  };

  /*
   * ⚠️ BELOW THE HOOKS, NEVER ABOVE THEM. An early return placed before
   * `useState`/`useEffect` changes how many hooks run between two renders of the
   * same component, which React refuses outright.
   */
  if (hidesFloatingChrome(pathname)) return null;

  return (
    // end-6, not right-6: the page is RTL, and a logical property puts these on
    // the correct side without a second rule.
    <div className="fixed bottom-[calc(var(--sticky-cta-height,0px)+1.5rem)] end-6 z-40 flex flex-col gap-3">
      {supportWhatsapp !== "" && (
        <a
          href={`https://wa.me/${supportWhatsapp}`}
          target="_blank"
          rel="noopener noreferrer"
          aria-label="تواصل معنا عبر واتساب"
          /* #128C7E, WhatsApp's darker brand green, not #25D366: the bright one
             sits at 1.98:1 against a white page, so neither the button's edge
             nor the white glyph inside it would clear the 3:1 floor for
             non-text contrast. This one clears both and is still the brand. */
          className="group flex h-12 w-12 items-center justify-center rounded-full bg-[#128C7E] text-white shadow-lg transition duration-200 hover:scale-110 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#128C7E] motion-reduce:transition-none motion-reduce:hover:scale-100"
        >
          <WhatsAppIcon className="h-6 w-6" />
        </a>
      )}

      <button
        type="button"
        onClick={scrollToTop}
        aria-label="العودة إلى أعلى الصفحة"
        /* Removed from the DOM rather than hidden when it does not apply: a
           permanently focusable button that scrolls nowhere is a tab stop that
           does nothing. */
        className={`flex h-12 w-12 items-center justify-center rounded-full border border-line bg-surface text-ink shadow-lg transition duration-200 hover:-translate-y-0.5 hover:border-primary hover:text-primary-ink hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none motion-reduce:hover:translate-y-0 ${
          showTop ? "animate-float-in" : "hidden"
        }`}
      >
        <ArrowUpIcon className="h-5 w-5" />
      </button>
    </div>
  );
}
