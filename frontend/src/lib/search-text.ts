/**
 * Client-side search over a list already on screen — the staff screens' filter
 * box (`FilterBar`).
 *
 * ⚠️ ARABIC IS NORMALISED BEFORE IT IS COMPARED, OR THE BOX LIES. A teacher types
 * «اعلان» and the record says «إعلان»; types «مدرسه» and it says «مدرسة»; the
 * title carries a shadda the keyboard never produced. A plain `includes()` says
 * «لا نتائج» to every one of them, about a record that is sitting right there.
 * So both sides lose the diacritics and the tatweel, and the letters a keyboard
 * spells two ways are folded to one: أ إ آ ٱ → ا · ى → ي · ة → ه · ؤ → و · ئ → ي.
 *
 * Digits are folded too (Arabic-Indic → Latin), because the screen shows «٢٠٢٦»
 * and the keyboard may type 2026.
 *
 * Written as `\u` escapes, not literal letters: a combining mark inside a
 * character class is invisible in review and renders on top of its bracket.
 */
export function normaliseSearchText(text: string): string {
  return (
    text
      .normalize("NFC")
      // Tashkeel (U+064B–U+065F), the dagger alef, and the Quranic annotation marks.
      .replace(/[\u064B-\u065F\u0670\u06D6-\u06ED]/g, "")
      // Tatweel.
      .replace(/\u0640/g, "")
      // Alef with hamza above/below, with madda, and alef wasla → bare alef.
      .replace(/[\u0622\u0623\u0625\u0671]/g, "\u0627")
      // Alef maqsura → yeh; teh marbuta → heh; hamza on waw → waw; hamza on yeh → yeh.
      .replace(/\u0649/g, "\u064A")
      .replace(/\u0629/g, "\u0647")
      .replace(/\u0624/g, "\u0648")
      .replace(/\u0626/g, "\u064A")
      // Arabic-Indic and Extended Arabic-Indic digits → Latin.
      .replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x0660))
      .replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
      .toLowerCase()
      .replace(/\s+/g, " ")
      .trim()
  );
}

/**
 * Whether a record matches what was typed. Every word of the query must appear
 * somewhere in the fields (in any order), so «رياضيات الصف» finds «الصف التاسع —
 * رياضيات». An empty query matches everything.
 */
export function matchesSearch(query: string, ...fields: Array<string | null | undefined>): boolean {
  const words = normaliseSearchText(query).split(" ").filter(Boolean);

  if (words.length === 0) return true;

  const haystack = normaliseSearchText(fields.filter(Boolean).join(" "));

  return words.every((word) => haystack.includes(word));
}
