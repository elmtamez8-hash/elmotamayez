import Link from "next/link";

import { arabicNumber } from "@/lib/numerals";
/**
 * Page links are real anchors carrying the full query string, so a given page of
 * a filtered search is shareable and crawlable (FR-050, SC-015).
 *
 * ⚠️ `basePath` IS REQUIRED. Every link was hard-coded to `/teachers` while
 * /courses and /store used this too, so page 2 of the courses or the store
 * landed on the teacher list with their filters attached. Required, `tsc`
 * names every caller.
 */
export function Pagination({
  basePath,
  currentPage,
  lastPage,
  searchParams,
}: {
  /** The list's own route, e.g. "/courses". */
  basePath: string;
  currentPage: number;
  lastPage: number;
  searchParams: Record<string, string | string[] | undefined>;
}) {
  if (lastPage <= 1) return null;

  const hrefFor = (page: number) => {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(searchParams)) {
      if (key !== "page" && typeof value === "string" && value !== "") {
        params.set(key, value);
      }
    }

    if (page > 1) params.set("page", String(page));

    const query = params.toString();

    return query === "" ? basePath : `${basePath}?${query}`;
  };

  // A window around the current page: 50 numbered links is not navigation.
  const start = Math.max(1, Math.min(currentPage - 2, lastPage - 4));
  const pages = Array.from(
    { length: Math.min(5, lastPage) },
    (_, i) => start + i,
  ).filter((page) => page <= lastPage);

  const linkClass =
    "grid min-w-10 place-items-center rounded-full border border-line bg-surface-raised px-4 py-2 text-sm font-bold text-ink transition hover:border-primary hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary";

  return (
    <nav aria-label="تصفّح الصفحات" className="mt-10 flex justify-center">
      <ul className="flex items-center gap-2">
        {currentPage > 1 && (
          <li>
            <Link href={hrefFor(currentPage - 1)} className={linkClass} rel="prev">
              السابق
            </Link>
          </li>
        )}

        {pages.map((page) => (
          <li key={page}>
            <Link
              href={hrefFor(page)}
              aria-current={page === currentPage ? "page" : undefined}
              className={
                page === currentPage
                  ? "grid min-w-10 place-items-center rounded-full bg-primary px-4 py-2 text-sm font-extrabold text-white shadow-md shadow-primary/20"
                  : linkClass
              }
            >
              {arabicNumber(page)}
            </Link>
          </li>
        ))}

        {currentPage < lastPage && (
          <li>
            <Link href={hrefFor(currentPage + 1)} className={linkClass} rel="next">
              التالي
            </Link>
          </li>
        )}
      </ul>
    </nav>
  );
}
