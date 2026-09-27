"use client";

import { useEffect, useRef, type ReactNode } from "react";

/**
 * The CSS variable a fixed bottom bar publishes its height through.
 *
 * ⚠️ ONE NAME, READ BY `FloatingActions`' class. A typo on either side does not
 * fail — `var()` falls back to `0px` and the WhatsApp button lands on the bar
 * again — so `FloatingActions.test.tsx` asserts its class carries this constant.
 */
export const STICKY_CTA_HEIGHT_VAR = "--sticky-cta-height";

/**
 * A call-to-action bar fixed to the bottom of the screen on a phone.
 *
 * It measures itself and writes the height to `<html>`, so floating chrome can
 * stand ABOVE it instead of guessing. The WhatsApp button used to sit at a fixed
 * `bottom-24` on every mobile page — a guess at this bar's height that left a few
 * pixels between the two on the teacher page and floated needlessly high on
 * every page that has no bar at all.
 *
 * `display: none` (the bar is `lg:hidden`) measures 0, so on a wide screen the
 * variable is 0 and nothing moves. Removed on unmount: a stale value would keep
 * the button raised on the next page.
 */
export function StickyCtaBar({ className, children }: { className: string; children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = ref.current;
    if (el === null) return;

    const root = document.documentElement;
    const publish = () => root.style.setProperty(STICKY_CTA_HEIGHT_VAR, `${el.offsetHeight}px`);

    publish();

    // jsdom and a few old browsers have no ResizeObserver; a resize listener is
    // enough there, because the bar changes height only with the viewport.
    if (typeof ResizeObserver === "undefined") {
      window.addEventListener("resize", publish);

      return () => {
        window.removeEventListener("resize", publish);
        root.style.removeProperty(STICKY_CTA_HEIGHT_VAR);
      };
    }

    const observer = new ResizeObserver(publish);
    observer.observe(el);

    return () => {
      observer.disconnect();
      root.style.removeProperty(STICKY_CTA_HEIGHT_VAR);
    };
  }, []);

  return (
    <div ref={ref} className={className}>
      {children}
    </div>
  );
}
