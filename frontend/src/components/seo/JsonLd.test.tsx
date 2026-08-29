import { describe, it, expect } from "vitest";
import { serializeJsonLd, absoluteHttpUrl } from "./JsonLd";

/*
| ⚠️ `dangerouslySetInnerHTML` ESCAPES NOTHING, AND EVERY FIELD IN A JSON-LD BLOCK
| COMES FROM A TEACHER'S KEYBOARD.
|
| `JSON.stringify` is not the guard people assume it is: it leaves `<` untouched,
| and the HTML parser hunts for the literal characters `</script` inside a script
| element without caring that they sit inside a JSON string. So an article titled
| `</script><script>…` closes our tag and opens theirs, on a page served to
| anonymous visitors.
|
| Only a component test can see this. The backend does not know the tag exists,
| and Playwright would need a page built from a title nobody would type by
| accident — which is precisely the title an attacker types on purpose.
*/
describe("serializeJsonLd", () => {
  it("cannot be closed by a title that contains a closing script tag", () => {
    const html = serializeJsonLd({ headline: "</script><script>alert(1)</script>" });

    // The literal sequence, in any case, is what the parser looks for.
    expect(html.toLowerCase()).not.toContain("</script");
    expect(html).not.toContain("<");
    expect(html).not.toContain(">");
  });

  it("escapes the ampersand as well, so no entity can be reassembled", () => {
    const html = serializeJsonLd({ headline: "&lt;script&gt;" });

    expect(html).not.toContain("&");
    expect(html).toContain("\\u0026");
  });

  it("still round-trips to the original string", () => {
    // ⚠️ THE HALF THAT MAKES THE ESCAPING FREE. `\\u003c` is what every JSON
    // reader — including every search engine's — decodes back to `<`, so this is
    // not a sanitiser that drops characters: the structured data a crawler reads
    // is byte-for-byte the title the teacher typed.
    const headline = "أقواس <زاوية> و& علامة، و</script> كذلك";

    expect(JSON.parse(serializeJsonLd({ headline })).headline).toBe(headline);
  });

  it("escapes the two separators that are line terminators in JavaScript", () => {
    const html = serializeJsonLd({ headline: "أ\u2028ب\u2029ج" });

    expect(html).toContain("\\u2028");
    expect(html).toContain("\\u2029");
    expect(JSON.parse(html).headline).toBe("أ\u2028ب\u2029ج");
  });

  it("leaves ordinary Arabic alone", () => {
    // A guard that mangled the common case would be caught by nothing else here:
    // every other assertion is about characters a title rarely contains.
    const headline = "خطّة المراجعة النهائية";

    expect(serializeJsonLd({ headline })).toBe(JSON.stringify({ headline }));
  });
});

describe("absoluteHttpUrl", () => {
  /*
  | `canonical_url` is a teacher-typed field emitted as `<link rel="canonical">`
  | — the one tag that tells a search engine which address of a page to keep. The
  | API validates it as a URL today; the rows already in the table predate that
  | rule, so the page checks again rather than trusting the column.
  */
  it("accepts an absolute http(s) URL", () => {
    expect(absoluteHttpUrl("https://example.qa/a")).toBe("https://example.qa/a");
    expect(absoluteHttpUrl("http://example.qa/a")).toBe("http://example.qa/a");
  });

  it.each([
    ["a relative path", "/blog/x"],
    ["a bare host", "example.qa/a"],
    ["a javascript URL", "javascript:alert(1)"],
    ["a data URL", "data:text/html,<script>alert(1)</script>"],
    ["nothing", ""],
    ["null", null],
  ])("refuses %s", (_label, value) => {
    expect(absoluteHttpUrl(value)).toBeNull();
  });
});
