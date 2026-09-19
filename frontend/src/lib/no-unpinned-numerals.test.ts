import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/*
| EVERY NUMBER ON EVERY SCREEN IS DRAWN BY `numerals.ts`, AND A BARE LOCALE
| STRING IS WHAT UNDOES THAT.
|
| ⛔ `toLocaleString("ar-QA")` WITHOUT `-u-nu-arab` IS ICU-DEPENDENT: «١٠٣» on
| ICU 77 and «103» on ICU 78 — the same source, two shapes, decided by whichever
| library the visitor's browser happens to ship. `numerals.ts` pins it; thirty-two
| call sites had written the locale out by hand instead, in TWO different locales
| (`ar-QA` and `ar-EG`), which is the «five spellings of one rule» shape this
| product already paid for once over the counted noun.
|
| ⚠️ THE GUARD IS A GREP, AND A GREP ALONE, BECAUSE NOTHING ELSE SEES IT. `tsc`
| is perfectly happy with a locale string, a component test renders under ONE
| ICU so it agrees with itself, and the failure is a digit shape nobody screenshots.
|
| ⚠️ AND IT ALLOWS A DATE. `Date.prototype.toLocaleString` shares its name with
| the number one, and dates are a different decision with the opposite answer —
| `formatDate()` pins `latn` on purpose. So the RECEIVER is what is judged, and
| `session-format.ts` plus the two panel screens that format an instant keep
| theirs.
*/

const SRC = join(process.cwd(), "src");

/** A number-formatting call naming an Arabic locale without the pin. */
const UNPINNED = /\.toLocaleString\(\s*["'`]ar(?!-[A-Z]{2}-u-nu-arab)/g;

/**
 * ⚠️ A DATE IS NOT A NUMBER, AND THE RECEIVER IS THE ONLY THING THAT SAYS SO.
 * `Date.prototype.toLocaleString` shares its name with the number one, so the
 * call alone cannot be judged — `session-format.ts` formats an instant and
 * `formatDate()` pins `latn` on purpose. The receiver is read from the text
 * just before the call, which is what every one of them looks like today
 * (`new Date(iso).toLocaleString(…)`); a date built further away would slip
 * through, and that is a narrower hole than refusing every date in the tree.
 */
function isDateCall(source: string, at: number): boolean {
  return source.slice(Math.max(0, at - 60), at).includes("Date(");
}

function offendsIn(source: string): boolean {
  UNPINNED.lastIndex = 0;

  for (const match of source.matchAll(UNPINNED)) {
    if (!isDateCall(source, match.index)) return true;
  }

  return false;
}

/** The one file allowed to name the locale: it is where the decision is written. */
const OWNER = join("src", "lib", "numerals.ts");

function tsFilesUnder(dir: string): string[] {
  const out: string[] = [];

  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) {
      out.push(...tsFilesUnder(path));
      continue;
    }

    if (/\.tsx?$/.test(entry)) out.push(path);
  }

  return out;
}

/**
 * ⚠️ COMMENTS ARE STRIPPED FIRST. Both `numerals.ts` and this very file explain
 * the rule by quoting the call it forbids, and a red build over its own
 * explanation teaches the next person to delete the explanation — the lesson
 * `theme-tokens.test.ts` and `ContextIsolationTest` each wrote down already.
 */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
}

describe("numerals are pinned in one place", () => {
  it("has no hand-written Arabic locale outside numerals.ts", () => {
    const offenders = tsFilesUnder(SRC)
      .filter((path) => !path.endsWith(OWNER))
      .filter((path) => offendsIn(withoutComments(readFileSync(path, "utf8"))));

    expect(offenders).toEqual([]);
  });

  it("pins the numbering system where the decision lives", () => {
    // ⛔ THE POSITIVE HALF. The check above is equally green on a tree that
    // formats no numbers at all, or on one whose owner quietly dropped the pin.
    expect(readFileSync(join(SRC, "lib", "numerals.ts"), "utf8")).toContain("ar-QA-u-nu-arab");
  });
});
