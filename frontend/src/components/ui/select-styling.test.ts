import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/*
| ⚠️ A `<Select>` WITH NO `className` PAINTS ALMOST NOTHING, SILENTLY.
|
| `Select` is the chevron and the lane it needs — `appearance-none` plus an
| absolutely positioned icon — and NOTHING else: every pixel of its border,
| background, padding, text colour and focus ring comes from the `className` its
| caller passes. Mount it bare and the browser draws its own control: visibly
| smaller than every field beside it, with no box at all, and an option list the
| browser paints white while the page's own light text stays on it. Reported by
| the user on 2026-09-06 about `/settings/profile`; this scan then found the same
| thing on `/subscribe`, one screen away, where nobody had noticed it.
|
| ⚠️ NEITHER `tsc` NOR A COMPONENT TEST CAN SEE IT. `className` is optional by
| type, and a render assertion about a value the component was never given passes
| against the unstyled control just as happily. It is the family of the undefined
| colour token this repository has now shipped four times: present, correct
| looking, and invisible.
|
| The fix at a call site is `SelectField`, the paired form that applies `CONTROL`
| and the error border. A raw `Select` stays legal WITH a className — a table cell
| running on tight padding is a real case (`/orders`) — which is exactly what this
| asks for: a deliberate appearance, not a forgotten one.
|
| ⚠️ COMMENTS ARE STRIPPED FIRST, for the reason `theme-tokens.test.ts` and
| `ContextIsolationTest` strip them: this very rule is written beside the fixed
| call sites, and a red build over an explanation teaches people to delete the
| explanation.
*/

function codeWithoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
}

/** The opening tag of every `<Select …>` in a file. */
function selectTags(source: string): string[] {
  const tags: string[] = [];

  for (const match of source.matchAll(/<Select\b/g)) {
    let depth = 0;
    let i = match.index + match[0].length;

    while (i < source.length) {
      const char = source[i];

      if (char === "{") depth += 1;
      else if (char === "}") depth -= 1;
      else if (char === ">" && depth === 0) break;

      i += 1;
    }

    tags.push(source.slice(match.index, i));
  }

  return tags;
}

/*
| ⚠️ A HAND-ROLLED WALK, BECAUSE `globSync` DOES NOT EXIST WHERE THIS RUNS.
| `node:fs`'s `globSync` arrived in Node 22; CI pins Node 20
| (`.github/workflows/ci.yml`), and local development is on 24. So this file threw
| `TypeError: globSync is not a function` on every CI run while passing on every
| developer machine — **the guard was green locally and had never once executed in
| the place that gates a merge**, which is the same shape as the defect it exists
| to catch: present, correct-looking, and doing nothing. Found on 2026-09-06 when a
| pull request was opened for the branch that added it.
*/
function tsxFilesUnder(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);

    if (entry.isDirectory()) return tsxFilesUnder(path);

    return entry.isFile() && entry.name.endsWith(".tsx") ? [path] : [];
  });
}

describe("every <Select> is given an appearance", () => {
  it("never mounts one without a className", () => {
    const files = tsxFilesUnder("src").filter(
      // The component's own file defines it and its `SelectField` pairing.
      (file) => !file.replace(/\\/g, "/").endsWith("components/ui/Field.tsx"),
    );

    // A positive control: the scan is worthless if it finds no selects at all —
    // a broken glob would report a clean tree for ever.
    const seen = files.flatMap((file) => selectTags(codeWithoutComments(readFileSync(file, "utf8"))));

    expect(seen.length).toBeGreaterThan(10);

    const offenders = files.flatMap((file) =>
      selectTags(codeWithoutComments(readFileSync(file, "utf8")))
        .filter((tag) => !tag.includes("className"))
        .map(() => file),
    );

    expect(offenders).toEqual([]);
  });
});
