import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/*
| توكنٌ لا وجودَ له لا يُخطئُ — يرسمُ لا شيءَ ويمرُّ.
|
| Tailwind v4 يقرأُ الألوانَ من `@theme`، فصنفٌ يسمّي توكناً غيرَ معرَّفٍ لا يُنتِجُ
| قاعدةً أصلاً: لا خطأَ بناء، ولا تحذير، ولا فرقَ في أيِّ لقطةِ شاشة — فقط نقطةٌ
| شفّافةٌ مكانَ العلامةِ الخضراء. حدث مرّتَين: `bg-success-soft` في `PracticeRunner`
| ثمّ `bg-success`/`border-success`/`text-success-ink` في غرفةِ البثّ ولوحةِ
| المشاركين، وكِلاهما كان له اختبارٌ أخضرُ يقيسُ `aria-label` فوقَ علامةٍ لا تُرى.
|
| ولهذا يقرأُ هذا الملفُّ `globals.css` بدلاً من أن يحملَ قائمةً: قائمةٌ مكتوبةٌ هنا
| هي إجابةٌ ثانيةٌ لسؤالٍ واحد، وتشيخُ في أوّلِ توكنٍ يُضاف.
*/

const SRC = join(process.cwd(), "src");

/** The colour names `@theme` actually defines. */
function definedTokens(): Set<string> {
  const css = readFileSync(join(SRC, "app", "globals.css"), "utf8");

  return new Set([...css.matchAll(/--color-([a-z0-9-]+)\s*:/g)].map((m) => m[1]));
}

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);

    if (entry.isDirectory()) return sourceFiles(path);

    return /\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name) ? [path] : [];
  });
}

/*
  Only the semantic families this product invents. Tailwind's own palette
  (`white`, `black`, `red-500`) and its keywords (`transparent`, `current`) are
  real classes that need no token, so a blanket scan would be noise — and noise
  is how a guard gets deleted. These four names are the ones somebody reaches for
  because they SOUND like our tokens.
*/
const TEMPTING = /\b(?:bg|text|border|ring|fill|from|to)-(surface-muted|success|error|info|muted)(?:-[a-z]+)?\b/g;

/**
 * Comments out, because a comment naming a dead class is the FIX being written
 * down, not the defect. `PracticeRunner` and both files below carry exactly that
 * note; scanning them raw makes the guard fire on its own documentation, and a
 * guard that cries over its own fix is one somebody deletes.
 */
function code(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
}

describe("theme tokens", () => {
  it("has no colour class naming a token @theme never defined", () => {
    const tokens = definedTokens();
    const offences: string[] = [];

    for (const file of sourceFiles(SRC)) {
      for (const match of code(readFileSync(file, "utf8")).matchAll(TEMPTING)) {
        // The full suffix, e.g. `success-ink` — a family may exist while the
        // shade a component asked for does not.
        const name = match[0].slice(match[0].indexOf("-") + 1);

        if (!tokens.has(name)) {
          offences.push(`${file.slice(SRC.length + 1)}: ${match[0]}`);
        }
      }
    }

    expect(offences).toEqual([]);
  });

  it("knows the tokens that DO exist, so the check above cannot pass by finding nothing", () => {
    const tokens = definedTokens();

    // The guard's own premise: if the parse broke, every name would be "missing"
    // and the test above would still be green only because nothing matched.
    expect(tokens.has("secondary")).toBe(true);
    expect(tokens.has("secondary-ink")).toBe(true);
    expect(tokens.has("success")).toBe(false);
  });
});
