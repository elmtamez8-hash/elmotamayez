import Link from "next/link";
import type { Taxonomy } from "@/lib/public-api";

/**
 * The subject filter, as links.
 *
 * ⚠️ NOT a `<select>` in a sidebar, and the reason is not aesthetic. Subject is
 * how a parent starts — "I need someone for physics" — and it was the fifth
 * control down a column of six identical dropdowns, indistinguishable from
 * "minimum trust score". A browsing surface whose primary axis is buried in a
 * form is a form.
 *
 * ⚠️ AND THEY ARE `<Link>`s, NOT BUTTONS. `TeacherFilters` is a client component
 * that pushes a new query string, so filtering does not work at all with
 * JavaScript off — SC-016 only ever tested that the listings were READABLE
 * without it. These are real hrefs: the crawler follows them, the visitor can
 * middle-click one, and the filter works before hydration.
 *
 * Server component on purpose. It needs the current params, which the page
 * already has, so nothing here needs to run in the browser.
 */
export function SubjectPills({
  subjects,
  params,
}: {
  subjects: Taxonomy[];
  /** The page's own searchParams, already narrowed to strings. */
  params: Record<string, string | undefined>;
}) {
  if (subjects.length === 0) return null;

  const current = params.subject ?? "";

  const hrefFor = (slug: string) => {
    const next = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
      // `page` is dropped with every filter change: keeping the old page number
      // is how a narrowed search lands on an empty page that reads as "no
      // results" when there are plenty on page one.
      if (value !== undefined && value !== "" && key !== "subject" && key !== "page") {
        next.set(key, value);
      }
    }

    if (slug !== "") next.set("subject", slug);

    const query = next.toString();

    return query === "" ? "/teachers" : `/teachers?${query}`;
  };

  const pill =
    "inline-flex items-center rounded-full border px-4 py-2 text-sm font-semibold transition duration-200 ease-out " +
    "active:scale-[0.97] active:duration-100 " +
    "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary";

  const options: { slug: string; name_ar: string; teachers_count?: number }[] = [
    { slug: "", name_ar: "كل المواد" },
    ...subjects,
  ];

  return (
    // A scroller, not a wrap, below sm: eleven pills wrapping to four rows push
    // the first teacher off a phone screen entirely.
    <div className="no-scrollbar -mx-4 overflow-x-auto px-4 pb-1 sm:mx-0 sm:overflow-visible sm:px-0">
      <ul className="flex gap-2 sm:flex-wrap">
        {options.map((option) => {
          const isCurrent = option.slug === current;

          return (
            <li key={option.slug || "all"} className="shrink-0">
              <Link
                href={hrefFor(option.slug)}
                scroll={false}
                // aria-current, not just colour: "which subject am I looking at"
                // must be answerable without seeing the fill.
                aria-current={isCurrent ? "true" : undefined}
                className={`${pill} ${
                  isCurrent
                    ? "border-primary bg-primary text-white"
                    : "border-line bg-surface-raised text-ink hover:border-primary hover:text-primary-ink"
                }`}
              >
                {option.name_ar}
                {option.teachers_count !== undefined && option.teachers_count > 0 && (
                  <span className={isCurrent ? "ms-2 opacity-80" : "ms-2 text-ink-muted"}>
                    {option.teachers_count.toLocaleString("ar-QA")}
                  </span>
                )}
              </Link>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
