import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/*
| Spec 033 · FR-012 · SC-001 — no browser dialog, ever again.
|
| ⚠️ A vitest FILE AND NOT AN ESLint RULE, and the reason is not taste. `no-alert`
| is the obvious answer and it would guard nothing here: `frontend/` has no
| ESLint config file at all (`eslint.config.*` and `.eslintrc*` are both absent,
| `package.json` just says `next lint`), and `.github/workflows/` does not
| mention `lint` in a single line. **A gate that does not run in CI is not a
| gate.** `npm test` runs everywhere, so this is where the rule lives — the same
| shape as `theme-tokens.test.ts` here, and `ProviderAgnosticTest` /
| `ContextIsolationTest` on the server.
|
| ⚠️ AND ITS TWO EXEMPTIONS ARE WHAT KEEP IT ALIVE, not softness:
|
|   COMMENTS ARE STRIPPED FIRST. Four files in this tree explain a decision with
|   the words `window.confirm()` in prose — `ConfirmButton`, `SilenceControl`,
|   `Modal` and two screens. A guard that goes red over the explanation written
|   beside the code teaches people to delete the explanation, which this
|   repository has now paid for twice (`TrustScoreJobIsolationTest` and
|   `theme-tokens.test.ts` both had to learn it).
|
|   TEST FILES ARE SKIPPED. Five of them carry `javascript:alert(1)` as INPUT to
|   an injection test. Failing on those teaches people to delete a security test,
|   which is worse than the thing this file prevents.
*/
/*
  ⚠️ `process.cwd()`, NOT `import.meta.url` — measured, twice, on this file.
  Vitest rewrites `import.meta.url` to a non-`file:` scheme during transform, so
  `fileURLToPath` throws «The URL must be of scheme file» before a single case
  runs; and `new URL(...).pathname` on Windows yields `/D:/mteatch/...`, whose
  leading slash sent the first attempt looking in `D:\src\src`. Vitest sets the
  working directory to the project root, which is exactly what is wanted here.
*/
const ROOT = process.cwd();

/** `alert(` · `confirm(` · `prompt(`, bare or on `window`, but never as a method
 *  of anything else — `contactVerification.confirm(...)` is an API call and
 *  `store.prompt(...)` would be too. The `window.` prefix is the ONE accepted
 *  qualifier, which is why it is spelled out rather than allowed by `\w+\.`. */
const NATIVE_DIALOG = /(?<![.\w$])(?:window\s*\.\s*)?(alert|confirm|prompt)\s*\(/;

/**
 * Remove block comments, line comments and string literals before scanning.
 *
 * ⚠️ STRINGS TOO, and that is not over-reach: `"javascript:alert(1)"` appears in
 * production source as a value being REJECTED — a URL allowlist test fixture is
 * one refactor away from living beside the code it guards. A call is never
 * inside a string literal, so nothing real is lost.
 */
function strip(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|[^:])\/\/.*$/gm, "$1")
    .replace(/`(?:\\.|[^`\\])*`/g, "``")
    .replace(/"(?:\\.|[^"\\\n])*"/g, '""')
    .replace(/'(?:\\.|[^'\\\n])*'/g, "''");
}

/** Every production source file: `src/**` minus tests. */
function productionFiles(dir: string, found: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);

    if (entry.isDirectory()) {
      productionFiles(path, found);

      continue;
    }

    if (!/\.tsx?$/.test(entry.name)) continue;
    if (/\.test\.tsx?$/.test(entry.name)) continue;

    found.push(path);
  }

  return found;
}

/** @returns `"<relative path>:<line>"` for each offending call. */
export function findNativeDialogCalls(files: string[]): string[] {
  const hits: string[] = [];

  for (const file of files) {
    const lines = strip(readFileSync(file, "utf8")).split("\n");

    lines.forEach((line, index) => {
      if (NATIVE_DIALOG.test(line)) {
        hits.push(`${file.replace(ROOT, "")}:${index + 1}`);
      }
    });
  }

  return hits;
}

describe("no native browser dialogs", () => {
  it("SC-001 — production source contains none", () => {
    const hits = findNativeDialogCalls(productionFiles(join(ROOT, "src")));

    /*
      The message names the file AND the line, because the fix is «use `Modal`
      from `components/ui/`» and the person reading a red build needs to know
      where, not that a rule exists.
    */
    expect(hits, `Native browser dialog(s) found — use <Modal> instead:\n  ${hits.join("\n  ")}`)
      .toEqual([]);
  });

  /*
  | ⛔ THE FOUR CASES BELOW ARE THE GUARD'S OWN TESTS, AND THEY ARE NOT CEREMONY.
  |
  | A scanner is the one kind of test that can be permanently, invisibly wrong:
  | a regex that matches nothing passes the case above for ever, over a tree full
  | of the thing it exists to forbid. These drive it with known input.
  */
  const scan = (source: string) => NATIVE_DIALOG.test(strip(source));

  it("G-01 · G-02 — catches every spelling of a real call", () => {
    expect(scan('if (!window.confirm("x")) return;')).toBe(true);
    expect(scan("alert(message);")).toBe(true);
    expect(scan('const t = prompt("عنوان");')).toBe(true);
    expect(scan("window . confirm ( x )")).toBe(true);
  });

  it("G-03 — a comment explaining the rule is not a violation of it", () => {
    // Four files in this tree carry exactly this prose.
    expect(scan("// Two clicks, not window.confirm(): a native dialog blocks the page")).toBe(false);
    expect(scan("/* `window.confirm` is untranslated on some Arabic Android builds */")).toBe(false);
    expect(scan(" * It replaces `window.confirm`/`window.prompt`, which are not ours")).toBe(false);
  });

  it("G-04 — injection fixtures are input, not calls", () => {
    expect(scan('videoId={\'" onload="alert(1)\'}')).toBe(false);
    expect(scan('["a javascript URL", "javascript:alert(1)"]')).toBe(false);
  });

  it("G-05 — an API method that happens to be named `confirm` is not a dialog", () => {
    /*
    | `contactVerification.confirm()` is the endpoint that verifies a WhatsApp
    | number. A guard keyed on the bare word would fail the build over it, and
    | the obvious «fix» is renaming a perfectly good API method after a linting
    | rule.
    */
    expect(scan("contactVerification.confirm(code)")).toBe(false);
    expect(scan("await auth.confirm(challenge);")).toBe(false);
    expect(scan("this.prompt(text)")).toBe(false);
  });
});
