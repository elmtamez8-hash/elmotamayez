"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * A tab strip, with the keyboard behaviour that makes one a tab strip.
 *
 * ⚠️ THE ACCESSIBILITY IS THE WHOLE REASON THIS IS A COMPONENT — the same
 * argument `ProgressBar` was extracted under. A row of buttons that swaps a
 * panel *looks* finished and is unusable without a mouse: the pattern owes a
 * reader `role="tablist"`, one `aria-selected` that moves, arrow keys that walk
 * the strip, `Home`/`End`, and a roving `tabIndex` so Tab leaves the strip
 * instead of walking through six of them. Hand-rolled per screen, the second
 * copy loses one of those and nobody sees it.
 *
 * No free-form `className`: appearance is a closed set, same rule as `Button`
 * and `Badge`.
 *
 * ⚠️ THE ARROWS ARE MAPPED LOGICALLY, AND IN THIS PRODUCT THAT MEANS ARROWLEFT
 * IS "NEXT". The frontend is Arabic-only and RTL-only by constitution (spec
 * 002), so the first tab sits at the RIGHT edge and the strip runs leftwards.
 * `ArrowRight` moving "forward" would walk a reader backwards past the tab they
 * started on. Hardcoded rather than detected: there is one direction here, and a
 * `getComputedStyle(...).direction` branch would be a second answer to a
 * question the root layout already settled.
 */

export interface TabDefinition {
  /** Stable, and what lands in `?tab=` — never the Arabic label. */
  key: string;
  label: string;
  /** A count beside the label: unread announcements, sessions today. */
  badge?: number;
}

/**
 * Which tab is open, kept in the address bar (FR-021).
 *
 * ⚠️ READ FROM `location` IN AN EFFECT, NOT WITH `useSearchParams`. That hook
 * opts the page out of static prerendering unless it sits inside a `<Suspense>`
 * boundary — the same reason `manage/courses/[uuid]/content` reads it this way.
 *
 * ⚠️ AND `replaceState`, NEVER `push`. A tab is a view of one page, not a
 * destination: pushing means the back button walks a student back through six
 * tabs before it leaves the course, and on a phone that is the button they use
 * to leave.
 *
 * An unknown or absent `?tab=` falls back to the first tab rather than showing
 * nothing — a hand-typed URL is a reader's mistake, not a reason for a blank
 * screen.
 */
export function useTabParam(tabs: TabDefinition[], param = "tab"): [string, (key: string) => void] {
  const first = tabs[0]?.key ?? "";
  const [active, setActive] = useState(first);

  useEffect(() => {
    const wanted = new URLSearchParams(window.location.search).get(param);

    if (wanted !== null && tabs.some((tab) => tab.key === wanted)) {
      setActive(wanted);
      return;
    }

    // The tab that WAS open may have disappeared — a course whose last session
    // was cancelled loses its sessions tab (FR-014), and a strip that keeps
    // pointing at it renders an empty panel with a selected tab above it.
    setActive((current) => (tabs.some((tab) => tab.key === current) ? current : first));
  }, [tabs, param, first]);

  const select = useCallback(
    (key: string) => {
      setActive(key);

      const url = new URL(window.location.href);
      url.searchParams.set(param, key);
      window.history.replaceState(null, "", url);
    },
    [param],
  );

  return [active, select];
}

export function Tabs({
  tabs,
  active,
  onChange,
  label,
}: {
  tabs: TabDefinition[];
  active: string;
  onChange: (key: string) => void;
  /** What this strip is a set of tabs FOR — the tablist's accessible name. */
  label: string;
}) {
  const strip = useRef<HTMLDivElement>(null);

  const move = (to: number) => {
    const next = tabs[to];
    if (next === undefined) return;

    onChange(next.key);
    // Selection follows focus, which is the expected behaviour for a tablist
    // whose panels are already loaded — and the focus has to move with it or
    // the next arrow press starts from the tab the reader left behind.
    strip.current?.querySelectorAll<HTMLButtonElement>("[role=tab]")[to]?.focus();
  };

  const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    const at = tabs.findIndex((tab) => tab.key === active);
    if (at < 0) return;

    switch (event.key) {
      // RTL: the strip runs leftwards, so Left is forward. See the note above.
      case "ArrowLeft":
        move((at + 1) % tabs.length);
        break;
      case "ArrowRight":
        move((at - 1 + tabs.length) % tabs.length);
        break;
      case "Home":
        move(0);
        break;
      case "End":
        move(tabs.length - 1);
        break;
      default:
        return;
    }

    event.preventDefault();
  };

  return (
    <div
      ref={strip}
      role="tablist"
      aria-label={label}
      onKeyDown={onKeyDown}
      className="flex gap-1 overflow-x-auto border-b border-line"
    >
      {tabs.map((tab) => {
        const selected = tab.key === active;

        return (
          <button
            key={tab.key}
            type="button"
            role="tab"
            id={`tab-${tab.key}`}
            aria-selected={selected}
            aria-controls={`panel-${tab.key}`}
            // Roving: exactly one tab is in the tab order, so Tab leaves the
            // strip for the panel instead of walking every tab in it.
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(tab.key)}
            className={`-mb-px shrink-0 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
              selected
                ? "border-primary text-primary-ink"
                : "border-transparent text-ink-muted hover:text-ink"
            }`}
          >
            {tab.label}
            {tab.badge !== undefined && tab.badge > 0 && (
              <span className="ms-1.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-xs text-primary-ink">
                {tab.badge}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

/**
 * The panel under the strip. Separate so the page owns what goes inside it and
 * the wiring — `id`, `aria-labelledby`, and being hidden rather than unmounted —
 * cannot be forgotten per screen.
 */
export function TabPanel({
  tabKey,
  active,
  children,
}: {
  tabKey: string;
  active: string;
  children: React.ReactNode;
}) {
  if (tabKey !== active) return null;

  return (
    <div role="tabpanel" id={`panel-${tabKey}`} aria-labelledby={`tab-${tabKey}`} tabIndex={0}>
      {children}
    </div>
  );
}
