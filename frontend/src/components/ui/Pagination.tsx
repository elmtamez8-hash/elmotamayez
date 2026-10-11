import Link from "next/link";

import { ChevronEndIcon, ChevronStartIcon } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";

/**
 * The one paginator for every public list — the store, the courses, the
 * teachers, a teacher's reviews (owner request 2026-10-11).
 *
 * Page links are real anchors carrying the full query string, so a given page of
 * a filtered search is shareable and crawlable (FR-050, SC-015).
 *
 * ⚠️ `basePath` IS REQUIRED. Every link was hard-coded to `/teachers` while
 * /courses and /store used this too, so page 2 of the courses or the store
 * landed on the teacher list with their filters attached. Required, `tsc`
 * names every caller.
 *
 * `pageParam` is for a list that is one part of a page (a teacher's reviews use
 * `reviews_page`, so paging them moves nothing else), and `hash` brings the
 * reader back to that list rather than to the top of the page.
 */
export function Pagination({
  basePath,
  currentPage,
  lastPage,
  searchParams,
  pageParam = "page",
  hash,
  label = "تصفّح الصفحات",
}: {
  /** The list's own route, e.g. "/courses". */
  basePath: string;
  currentPage: number;
  lastPage: number;
  searchParams: Record<string, string | string[] | undefined>;
  pageParam?: string;
  /** Without the «#», e.g. "reviews". */
  hash?: string;
  label?: string;
}) {
  if (lastPage <= 1) return null;

  const hrefFor = (page: number) => {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(searchParams)) {
      if (key !== pageParam && typeof value === "string" && value !== "") {
        params.set(key, value);
      }
    }

    if (page > 1) params.set(pageParam, String(page));

    const query = params.toString();

    return `${query === "" ? basePath : `${basePath}?${query}`}${hash ? `#${hash}` : ""}`;
  };

  const pages = pageWindow(currentPage, lastPage);

  const pill =
    "grid h-10 min-w-10 place-items-center rounded-full px-3 text-sm font-bold transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none";
  const idle = `${pill} border border-line bg-surface-raised text-ink hover:border-primary hover:bg-primary-soft hover:text-primary-ink`;
  const step = `inline-flex h-10 items-center gap-1.5 rounded-full border border-line bg-surface-raised px-3 text-sm font-bold text-ink transition duration-200 hover:border-primary hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none sm:px-4`;
  // An end that does not exist keeps its place, so the row never jumps.
  const stepOff = "inline-flex h-10 items-center gap-1.5 rounded-full border border-line/60 px-3 text-sm font-bold text-ink-muted/50 sm:px-4";

  return (
    <nav aria-label={label} className="mt-10 flex flex-col items-center gap-3">
      <ul className="flex items-center gap-1.5 sm:gap-2">
        <li>
          {currentPage > 1 ? (
            <Link href={hrefFor(currentPage - 1)} className={step} rel="prev">
              <ChevronStartIcon className="h-4 w-4" aria-hidden />
              <span>السابق</span>
            </Link>
          ) : (
            <span className={stepOff} aria-disabled="true">
              <ChevronStartIcon className="h-4 w-4" aria-hidden />
              <span>السابق</span>
            </span>
          )}
        </li>

        {/* The numbers on a wide screen; a phone reads «صفحة ٢ من ٥» below. */}
        {pages.map((page, index) =>
          page === null ? (
            <li key={`gap-${index}`} aria-hidden="true" className="hidden px-1 text-ink-muted sm:block">
              …
            </li>
          ) : (
            <li key={page} className="hidden sm:block">
              <Link
                href={hrefFor(page)}
                aria-current={page === currentPage ? "page" : undefined}
                aria-label={`الصفحة ${arabicNumber(page)}`}
                className={
                  page === currentPage
                    ? `${pill} bg-primary font-extrabold text-white shadow-md shadow-primary/25`
                    : idle
                }
              >
                {arabicNumber(page)}
              </Link>
            </li>
          ),
        )}

        <li>
          {currentPage < lastPage ? (
            <Link href={hrefFor(currentPage + 1)} className={step} rel="next">
              <span>التالي</span>
              <ChevronEndIcon className="h-4 w-4" aria-hidden />
            </Link>
          ) : (
            <span className={stepOff} aria-disabled="true">
              <span>التالي</span>
              <ChevronEndIcon className="h-4 w-4" aria-hidden />
            </span>
          )}
        </li>
      </ul>

      <p className="text-xs font-semibold text-ink-muted">
        صفحة {arabicNumber(currentPage)} من {arabicNumber(lastPage)}
      </p>
    </nav>
  );
}

/**
 * The page numbers to draw, `null` for a gap: always the first and the last,
 * and the current page with one either side — 50 numbered links is not
 * navigation, and a gap of ONE page is drawn as that page rather than «…».
 */
export function pageWindow(current: number, last: number): (number | null)[] {
  const wanted = new Set([1, last, current - 1, current, current + 1]);
  const pages = [...wanted].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out: (number | null)[] = [];

  for (const page of pages) {
    const previous = out[out.length - 1];

    if (typeof previous === "number" && page - previous === 2) out.push(previous + 1);
    else if (typeof previous === "number" && page - previous > 2) out.push(null);

    out.push(page);
  }

  return out;
}
