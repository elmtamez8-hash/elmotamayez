import type { AnyExtension, Editor } from "@tiptap/core";
import Image from "@tiptap/extension-image";
import Paragraph from "@tiptap/extension-paragraph";
import { Markdown } from "@tiptap/markdown";
import StarterKit from "@tiptap/starter-kit";

/**
 * The editor's schema — and therefore the whole of what it can write.
 *
 * ⚠️ **THE SERVER IS THE REFERENCE, NOT THIS FILE.** `MarkdownRenderer` runs
 * league/commonmark with `CommonMarkCoreExtension` ONLY and `html_input: strip`:
 * no strikethrough, no tables, no task lists, no underline, no raw HTML. A node
 * or mark here that the server cannot render is a button that works in the
 * editor and vanishes on the published page, so the schema is CommonMark's core
 * and nothing else:
 *
 *   bold · italic · headings · bullet/ordered lists · blockquote · inline code ·
 *   fenced code · horizontal rule · link · image · hard break
 *
 * A pasted `<u>`, `<span style>`, `<table>` or `<script>` has no node to land in,
 * so ProseMirror's parser keeps its text and drops the tag — the paste becomes
 * Markdown by construction, not by a sanitiser.
 *
 * Shared by the component and its tests on purpose: a test that builds its own
 * extension list proves a schema nobody ships.
 */
export function richMarkdownExtensions(): AnyExtension[] {
  return [
    StarterKit.configure({
      // Not in CommonMark core: `~~x~~` and `++x++` render as literal tildes
      // and plus signs on the published page.
      strike: false,
      underline: false,
      // Replaced below by the escaping paragraph.
      paragraph: false,
      link: {
        // Inside the editor a click places the caret; following the link would
        // navigate away from an unsaved article.
        openOnClick: false,
        autolink: true,
        defaultProtocol: "https",
        protocols: ["http", "https", "mailto"],
        isAllowedUri: (url) => safeHref(url) !== null,
      },
      // Heading levels are left at the default on purpose. The toolbar offers
      // H2 and H3 only, but narrowing the SCHEMA would turn an existing `#` or a
      // pasted `<h1>` into a paragraph — a silent edit of somebody's article.
    }),
    EscapingParagraph,
    // There is no body-image upload for articles, so the toolbar has no image
    // button. The node is here to KEEP images: without it every `![…](…)` in an
    // existing article is dropped the first time it is opened and saved.
    Image.configure({ allowBase64: false }),
    Markdown.configure({
      // `gfm: false` so the editor reads Markdown the way the server does. With
      // GFM on, `| a | b |` would parse as a table the schema has no node for,
      // and `~~x~~` as a strike mark it has no mark for.
      markedOptions: { gfm: false },
    }),
  ];
}

/**
 * The document as the Markdown that gets stored.
 *
 * Trailing whitespace is trimmed: the editor keeps an empty paragraph after a
 * list, heading or code block so the caret has somewhere to go, and it would
 * otherwise leave blank lines at the end of every body saved that way.
 */
export function toMarkdown(editor: Editor): string {
  return editor.getMarkdown().trimEnd();
}

/**
 * ⚠️ `@tiptap/markdown` escapes inline syntax (`* _ [ ] \` ~`) in text but NOT
 * the block markers at the start of a line — measured: a paragraph that reads
 * `\# not a heading` loads as the text «# not a heading» and serialises back as
 * `# not a heading`, which the server renders as a HEADING after one save.
 * The same for `1. `, `- `, `+ ` and a lone `---` (a rule). So each line of a
 * paragraph (a hard break starts a new one) is escaped here.
 *
 * `>` needs nothing: it already leaves as `&gt;`, which CommonMark decodes.
 */
export function escapeBlockStarts(markdown: string): string {
  return (
    markdown
      // Four spaces open an indented code block. Leading spaces carry no
      // meaning in a rendered paragraph, so they are dropped rather than kept.
      .replace(/^[ \t]+/gm, "")
      .replace(/^(#{1,6})(?=[ \t]|$)/gm, "\\$1")
      .replace(/^([-+])(?=[ \t])/gm, "\\$1")
      .replace(/^(\d{1,9})([.)])(?=[ \t]|$)/gm, "$1\\$2")
      // A line of only `-` or `=` is a rule or a setext underline.
      .replace(/^([-=])(?=[-= \t]*$)/gm, "\\$1")
  );
}

// The stock renderer is called with this extension's own `this`, and only its
// output is post-processed.
const renderParagraph = Paragraph.config.renderMarkdown;

const EscapingParagraph = Paragraph.extend({
  renderMarkdown(node, helpers, context) {
    return escapeBlockStarts(renderParagraph?.call(this, node, helpers, context) ?? "");
  },
});

/**
 * The only link targets the editor accepts: web, mail, and a path on this site.
 * The server's `allow_unsafe_links: false` blocks `javascript:` and `data:` at
 * render time; this refuses them before they are ever written.
 *
 * A bare `example.com` gets `https://` — what somebody typing a site name means.
 */
export function safeHref(raw: string): string | null {
  const url = raw.trim();
  if (url === "") return null;
  if (/^https?:\/\/[^\s]+$/i.test(url)) return url;
  if (/^mailto:[^\s@]+@[^\s@]+$/i.test(url)) return url;
  if (/^\/(?!\/)[^\s]*$/.test(url)) return url;
  if (/^[a-z0-9][a-z0-9-]*(\.[a-z0-9-]+)+(\/[^\s]*)?$/i.test(url)) return `https://${url}`;
  return null;
}
