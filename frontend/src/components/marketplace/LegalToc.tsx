"use client";

import { useEffect, useState } from "react";
import { arabicNumber } from "@/lib/numerals";

type Entry = { id: string; text: string };

/**
 * «في هذه الصفحة» — built from the page's own `<h2>`s after it renders.
 *
 * The headings are authored in two different ways (JSX on /terms and
 * /refunds, server-rendered Markdown on /privacy), so the list is read from the
 * DOM rather than declared beside each page: a declared list is a second copy
 * of the headings that drifts the day one is reworded. A heading without an id
 * is given one. Without JavaScript the aside is simply empty — the page itself
 * is complete without it.
 */
export function LegalToc() {
  const [entries, setEntries] = useState<Entry[]>([]);
  const [active, setActive] = useState<string | null>(null);

  useEffect(() => {
    // `h3` inside a policy too: /privacy's text is server Markdown whose
    // headings are demoted one level (`demoteHeadings()`), so its sections
    // arrive as `h3` under an `h2` that only repeats the page title — which is
    // left out, since the banner already says it.
    const title = document.querySelector("h1")?.textContent?.trim();
    const headings = Array.from(
      document.querySelectorAll<HTMLHeadingElement>(
        "[data-legal-body] h2, [data-legal-body] .prose-policy h3",
      ),
    ).filter((heading) => heading.textContent?.trim() !== title);

    headings.forEach((heading, index) => {
      if (heading.id === "") heading.id = `section-${index + 1}`;
      heading.classList.add("scroll-mt-28");
    });

    setEntries(headings.map((heading) => ({ id: heading.id, text: heading.textContent ?? "" })));

    // Without the API (old browsers, jsdom) the list still links; it just does
    // not follow the reader.
    if (typeof IntersectionObserver === "undefined") return;

    // The section being read is the last heading that has scrolled past the
    // top third of the screen.
    const observer = new IntersectionObserver(
      (records) => {
        const visible = records.filter((record) => record.isIntersecting);
        if (visible.length > 0) setActive(visible[0].target.id);
      },
      { rootMargin: "0px 0px -66% 0px" },
    );
    headings.forEach((heading) => observer.observe(heading));

    return () => observer.disconnect();
  }, []);

  if (entries.length === 0) return null;

  return (
    <nav aria-label="في هذه الصفحة">
      <p className="mb-4 text-xs font-extrabold text-ink-muted">في هذه الصفحة</p>
      <ol className="space-y-1 border-s border-line">
        {entries.map((entry, index) => {
          const current = entry.id === active;

          return (
            <li key={entry.id}>
              <a
                href={`#${entry.id}`}
                aria-current={current ? "location" : undefined}
                className={`-ms-px flex gap-3 border-s py-1.5 ps-4 text-sm leading-snug transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary ${
                  current
                    ? "border-primary font-bold text-primary-ink"
                    : "border-transparent text-ink-muted hover:border-line hover:text-ink"
                }`}
              >
                <span className="shrink-0 tabular-nums">{arabicNumber(index + 1)}</span>
                {entry.text}
              </a>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
