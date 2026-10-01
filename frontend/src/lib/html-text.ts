/**
 * The server's rendered Markdown as plain text — for the places that show TEXT
 * (a two-line card summary, a meta description, JSON-LD), not markup.
 *
 * The input is OUR renderer's output (`MarkdownRenderer::toHtml`, raw HTML
 * stripped at the parse), never user HTML, so a tag strip is enough; the result
 * is set as text, never as HTML. Blocks become line breaks so words do not run
 * together across paragraphs.
 */
const ENTITIES: Record<string, string> = {
  "&amp;": "&",
  "&lt;": "<",
  "&gt;": ">",
  "&quot;": '"',
  "&#39;": "'",
  "&#039;": "'",
  "&nbsp;": " ",
};

export function htmlToText(html: string | null | undefined): string {
  if (!html) return "";

  return html
    .replace(/<\/(p|li|h[1-6]|blockquote|pre)>|<br\s*\/?>/gi, "\n")
    .replace(/<[^>]*>/g, "")
    .replace(/&(amp|lt|gt|quot|nbsp|#39|#039);/g, (entity) => ENTITIES[entity] ?? entity)
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}
