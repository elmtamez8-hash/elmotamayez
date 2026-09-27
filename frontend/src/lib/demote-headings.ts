/**
 * Every heading in a block of rendered HTML moved one level down: `<h1>` to
 * `<h2>`, … `<h5>` to `<h6>` (an `<h6>` stays).
 *
 * ⚠️ FOR ONE PURPOSE: A PAGE HAS ONE `<h1>`, AND ON /privacy IT IS THE BANNER'S.
 * The policy's Markdown opens with its own `#` title, so the page carried two —
 * the document outline a screen reader and a search engine read had two roots.
 * Shifting every level (not only `h1`) keeps the policy's own hierarchy intact
 * beneath the banner.
 *
 * A regex is enough here and only here: the HTML comes from our own
 * `MarkdownRenderer`, which emits bare heading tags and strips raw HTML from the
 * source, so there is no attribute or comment for the pattern to trip on.
 */
export function demoteHeadings(html: string): string {
  return html.replace(/<(\/?)h([1-5])(?=[\s>])/gi, (_, slash: string, level: string) =>
    `<${slash}h${Number(level) + 1}`,
  );
}
