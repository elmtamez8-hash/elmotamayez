/**
 * A `<script type="application/ld+json">` block, written safely (011 · FR-035).
 *
 * ⚠️ `dangerouslySetInnerHTML` ESCAPES NOTHING — that is the whole reason it is
 * spelled «dangerously» — and every field in here comes from a teacher's
 * keyboard. An article titled `</script><script>…` closes our tag and opens
 * theirs, on a page served to anonymous visitors, with the payload sitting in the
 * head of a search result.
 *
 * JSON encoding alone does NOT close it: `JSON.stringify` leaves `<` untouched,
 * and the HTML parser looks for the literal characters `</script` inside a script
 * element without caring that they are inside a JSON string. So the three
 * characters that can start a tag or an entity are escaped to their `\uXXXX`
 * forms — which JSON readers, including every search engine's, decode back to
 * exactly the same string.
 *
 * There is no schema.org library here on purpose. The object is a dozen keys read
 * from data we already fetched, and a dependency that builds it would still have
 * to be handed the same dozen keys.
 */
export function JsonLd({ data }: { data: Record<string, unknown> }) {
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: serializeJsonLd(data) }}
    />
  );
}

/**
 * Exported for its test: the escaping is the component, and asserting on rendered
 * markup would measure React's serialiser as much as ours.
 */
export function serializeJsonLd(data: Record<string, unknown>): string {
  return (
    JSON.stringify(data)
      // `<` alone is enough to break out; `>` and `&` are escaped with it so no
      // reading of the surrounding markup can reassemble a tag or an entity.
      .replace(/</g, "\\u003c")
      .replace(/>/g, "\\u003e")
      .replace(/&/g, "\\u0026")
      // U+2028/U+2029 are valid in JSON and are line terminators in JavaScript.
      // Harmless inside `application/ld+json`, and one copy-paste away from not
      // being, so they go too.
      .replace(/\u2028/g, "\\u2028")
      .replace(/\u2029/g, "\\u2029")
  );
}

/**
 * An absolute `http(s)` URL, or null.
 *
 * ⚠️ `canonical_url` IS A TEACHER-TYPED FIELD AND IT IS EMITTED AS A `<link>` AND
 * INSIDE THE JSON-LD. The API validates it as a URL today; the rows already in
 * the table predate that rule, and a relative or `javascript:` value would be a
 * broken canonical on an indexed page — the one tag that tells a search engine
 * which address to keep.
 */
export function absoluteHttpUrl(value: string | null | undefined): string | null {
  if (!value) return null;

  try {
    const url = new URL(value);

    return url.protocol === "http:" || url.protocol === "https:"
      ? url.toString()
      : null;
  } catch {
    // `new URL()` throws on a relative value, which is the ordinary case here.
    return null;
  }
}
