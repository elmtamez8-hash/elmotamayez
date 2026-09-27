import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";

/**
 * What the panel shows inside `<main>` while the next page's code and data load.
 *
 * The shell (sidebar, header) is the layout and stays on screen; only the page
 * slot is swapped. Without this, a slow navigation left the PREVIOUS page on
 * screen and gave no sign the tap had registered.
 *
 * ⚠️ DELIBERATELY NOT MIRRORED IN `(public)`. A `loading.tsx` makes Next stream
 * the response with a 200 before the page runs, so a `notFound()` on a teacher,
 * course or article page would arrive as a soft 404 (200 + noindex) — and those
 * are the pages search engines index. The panel is behind sign-in, so it has no
 * such cost.
 */
export default function ShellLoading() {
  return <RowsSkeleton count={4} />;
}
