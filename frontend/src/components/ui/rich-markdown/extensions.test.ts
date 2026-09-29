import { Editor } from "@tiptap/core";
import { afterEach, describe, expect, it } from "vitest";

import { escapeBlockStarts, richMarkdownExtensions, safeHref, toMarkdown } from "./extensions";

/**
 * The rich editor stores Markdown, so its contract is a round trip: what the
 * server stored comes back out unchanged, an edit comes out as Markdown the
 * server's CommonMark-core renderer understands, and nothing it emits is HTML.
 *
 * Built on `richMarkdownExtensions()` — the production schema — never on a list
 * assembled here, which would test an editor nobody ships.
 */

const editors: Editor[] = [];

function load(markdown: string): Editor {
  const editor = new Editor({
    extensions: richMarkdownExtensions(),
    content: markdown,
    contentType: "markdown",
  });
  editors.push(editor);
  return editor;
}

afterEach(() => {
  while (editors.length) editors.pop()?.destroy();
});

const roundTrip = (markdown: string) => toMarkdown(load(markdown));

/**
 * ProseMirror's own paste path — the one a real Ctrl+V takes, parse rules and
 * paste handlers included. jsdom has no `ClipboardEvent`, which `pasteHTML`
 * constructs when it is given none, so a plain `paste` event stands in.
 */
function paste(editor: Editor, html: string) {
  editor.view.pasteHTML(html, new Event("paste") as ClipboardEvent);
}

describe("rich markdown editor — round trip", () => {
  it.each([
    ["bold", "نصّ **غامق** هنا"],
    ["italic", "نصّ *مائل* هنا"],
    ["heading 2", "## عنوان رئيسي"],
    ["heading 3", "### عنوان فرعي"],
    // An existing `#` stays a heading even though the toolbar offers only H2/H3.
    ["heading 1", "# عنوان المقال"],
    ["bullet list", "- أوّلاً\n- ثانياً"],
    ["numbered list", "1. واحد\n2. اثنان"],
    ["nested list", "- أ\n  - ب"],
    ["link", "اقرأ [الدليل](https://example.com/guide) كاملاً"],
    ["blockquote", "> قال المعلّم"],
    ["inline code", "استعمل `let x = 1` هنا"],
    ["code block", "```js\nconst a = 1;\n```"],
    ["horizontal rule", "قبل\n\n---\n\nبعد"],
    // No upload exists for body images, but existing ones must survive a save.
    ["image", "![صورة الدرس](https://example.com/a.png)"],
  ])("keeps %s unchanged", (_name, markdown) => {
    expect(roundTrip(markdown)).toBe(markdown);
  });

  it("serialises an edit made through the editor's commands as Markdown", () => {
    const editor = load("مرحبا\n\nسطر\n\nبند");

    // Bold the first paragraph, make the second a heading, the third a list.
    editor.commands.setTextSelection({ from: 1, to: 6 });
    editor.commands.toggleBold();
    editor.commands.setTextSelection(9);
    editor.commands.toggleHeading({ level: 2 });
    editor.commands.setTextSelection(14);
    editor.commands.toggleBulletList();

    // `toMarkdown`, not `getMarkdown`: the trailing empty paragraph the editor
    // keeps after a list must not leave blank lines at the end of the source.
    expect(toMarkdown(editor)).toBe("**مرحبا**\n\n## سطر\n\n- بند");
  });

  it("offers no mark the server cannot render", () => {
    const editor = load("");

    // CommonMark core has no strikethrough, underline or tables: a button for
    // any of them would work here and vanish on the published page.
    expect(editor.schema.marks.strike).toBeUndefined();
    expect(editor.schema.marks.underline).toBeUndefined();
    expect(editor.schema.nodes.table).toBeUndefined();
  });

  it("keeps a GFM-only table as the text it is, the way the server renders it", () => {
    const table = "| a | b |\n|---|---|\n| 1 | 2 |";
    expect(roundTrip(table)).toBe(table);
  });
});

describe("rich markdown editor — escaping", () => {
  it.each([
    ["\\# ليس عنواناً", "\\# ليس عنواناً"],
    ["2024\\. كانت سنة", "2024\\. كانت سنة"],
    ["\\- ليست قائمة", "\\- ليست قائمة"],
    ["\\+ ليست قائمة", "\\+ ليست قائمة"],
    ["\\*ليس مائلاً\\*", "\\*ليس مائلاً\\*"],
    ["\\[ليس رابطاً\\](x)", "\\[ليس رابطاً\\](x)"],
  ])("keeps the escape in %s so it does not become syntax after a save", (input, expected) => {
    // Measured before the fix: `\# x` came back as `# x` — a HEADING on the
    // published page after one save of an unrelated edit.
    expect(roundTrip(input)).toBe(expected);
  });

  it("escapes block markers at the start of every line, and only there", () => {
    expect(escapeBlockStarts("# a")).toBe("\\# a");
    expect(escapeBlockStarts("###### a")).toBe("\\###### a");
    expect(escapeBlockStarts("####### a")).toBe("####### a");
    expect(escapeBlockStarts("- a")).toBe("\\- a");
    expect(escapeBlockStarts("-a")).toBe("-a");
    expect(escapeBlockStarts("12. a")).toBe("12\\. a");
    expect(escapeBlockStarts("3) a")).toBe("3\\) a");
    expect(escapeBlockStarts("---")).toBe("\\---");
    expect(escapeBlockStarts("===")).toBe("\\===");
    expect(escapeBlockStarts("    code?")).toBe("code?");
    expect(escapeBlockStarts("سطر  \n# ثانٍ")).toBe("سطر  \n\\# ثانٍ");
    expect(escapeBlockStarts("نصّ # وسط - السطر 1. هنا")).toBe("نصّ # وسط - السطر 1. هنا");
  });
});

describe("rich markdown editor — HTML never gets in", () => {
  it("turns pasted HTML into Markdown and drops what CommonMark core cannot say", () => {
    const editor = load("");

    paste(
      editor,
      "<p>نصّ <strong>غامق</strong> و<em>مائل</em> و<u>مسطّر</u> و" +
        "<span style=\"color:red\">ملوّن</span> <a href=\"https://example.com\">رابط</a></p>" +
        "<h2>عنوان</h2><ul><li>بند</li></ul><blockquote><p>اقتباس</p></blockquote>" +
        "<script>alert(1)</script><img src=\"x\" onerror=\"alert(1)\">" +
        "<table><tr><td>خلية</td></tr></table>",
    );

    const markdown = editor.getMarkdown();

    expect(markdown).toContain("**غامق**");
    expect(markdown).toContain("*مائل*");
    expect(markdown).toContain("[رابط](https://example.com)");
    expect(markdown).toContain("## عنوان");
    expect(markdown).toContain("- بند");
    expect(markdown).toContain("> اقتباس");
    // The tag goes, its words stay.
    expect(markdown).toContain("مسطّر");
    expect(markdown).toContain("ملوّن");
    expect(markdown).toContain("خلية");

    expect(markdown).not.toMatch(/<\s*(script|u|span|table|td|strong|em|a|img)\b/i);
    expect(markdown).not.toContain("onerror");
    expect(markdown).not.toContain("alert(1)");
    expect(markdown).not.toContain("color:red");
  });

  it("drops a pasted javascript: link and keeps its text", () => {
    const editor = load("");

    paste(editor, '<p><a href="javascript:alert(1)">اضغط</a></p>');

    const markdown = editor.getMarkdown();
    expect(markdown).toContain("اضغط");
    expect(markdown).not.toContain("javascript:");
  });

  it("never writes a <script> that was in the stored Markdown back out", () => {
    const markdown = roundTrip("قبل <script>alert(1)</script> بعد\n\n<div onclick=\"x()\">كتلة</div>");

    expect(markdown).not.toMatch(/<\s*script/i);
    expect(markdown).not.toMatch(/<\s*div/i);
    expect(markdown).not.toContain("onclick");
    expect(markdown).toContain("قبل");
    expect(markdown).toContain("بعد");
  });
});

describe("safeHref", () => {
  it.each([
    ["https://example.com/a?b=1", "https://example.com/a?b=1"],
    ["http://example.com", "http://example.com"],
    ["mailto:a@example.com", "mailto:a@example.com"],
    ["/blog/مقال", "/blog/مقال"],
    ["example.com/path", "https://example.com/path"],
    ["  https://example.com  ", "https://example.com"],
  ])("accepts %s", (input, expected) => {
    expect(safeHref(input)).toBe(expected);
  });

  it.each(["javascript:alert(1)", "data:text/html,x", "//evil.com", "", "not a url", "vbscript:x"])(
    "refuses %s",
    (input) => {
      expect(safeHref(input)).toBeNull();
    },
  );
});
