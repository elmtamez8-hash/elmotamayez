"use client";

import { useEffect, useState } from "react";
import { ArrowUpIcon, WhatsAppIcon } from "@/components/icons";
import { SUPPORT_WHATSAPP } from "@/lib/platform";

const SHOW_AFTER_PX = 400;

/**
 * The floating WhatsApp and back-to-top buttons.
 *
 * One component for both so they share a stacking context and the same bottom
 * offset. The teacher profile page pins a booking bar to the bottom on mobile;
 * these sit above it (bottom-24) below `lg` and drop to bottom-6 once that bar
 * is gone. Two separate components would each have to know about that bar.
 */
export function FloatingActions() {
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

  return (
    // end-6, not right-6: the page is RTL, and a logical property puts these on
    // the correct side without a second rule.
    <div className="fixed bottom-24 end-6 z-40 flex flex-col gap-3 lg:bottom-6">
      {SUPPORT_WHATSAPP !== "" && (
        <a
          href={`https://wa.me/${SUPPORT_WHATSAPP}`}
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
