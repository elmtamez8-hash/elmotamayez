import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

import { teachesOnPlatform } from "./teaches-on-platform";

/*
| «Does this account teach here?» has ONE spelling: `teachesOnPlatform(user)`.
|
| ⚠️ `user.workspaces` is the server's list of workspaces this account holds a
| NON-STUDENT pivot role in — the same predicate as `User::teachesOnPlatform()`
| on the server, which the purchase doors refuse on. Three screens (the
| dashboard router, the withheld-students card and the side nav) spelt it by
| hand as `(user?.workspaces?.length ?? 0)`, so the day the predicate moves
| (a pivot role added, a field renamed) they would keep answering the old
| question while every other screen answers the new one.
|
| A vitest file, not a lint rule — `frontend/` runs no linter in CI (see
| `no-native-dialogs.test.ts`). Comments and strings are stripped first so the
| explanation written beside the rule is never a violation of it.
*/
const ROOT = process.cwd();
const HOME = join("src", "lib", "teaches-on-platform.ts");

/** `workspaces.length` / `workspaces?.length`, however it is reached. */
const HAND_SPELT = /\bworkspaces\s*\??\.\s*length\b/;

function strip(source: string): string {
  return source
    // Newlines kept, so the line a hit reports is the line in the file.
    .replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\n]/g, ""))
    .replace(/(^|[^:])\/\/.*$/gm, "$1")
    .replace(/`(?:\\.|[^`\\])*`/g, "``")
    .replace(/"(?:\\.|[^"\\\n])*"/g, '""')
    .replace(/'(?:\\.|[^'\\\n])*'/g, "''");
}

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

describe("teachesOnPlatform is the one spelling", () => {
  it("no production file counts `user.workspaces` by hand", () => {
    const hits: string[] = [];

    for (const file of productionFiles(join(ROOT, "src"))) {
      if (file.endsWith(HOME)) continue;

      strip(readFileSync(file, "utf8"))
        .split("\n")
        .forEach((line, index) => {
          if (HAND_SPELT.test(line)) hits.push(`${file.replace(ROOT, "")}:${index + 1}`);
        });
    }

    expect(hits, `Use teachesOnPlatform(user) instead:\n  ${hits.join("\n  ")}`).toEqual([]);
  });

  it("the guard catches the spellings it exists to forbid", () => {
    const scan = (source: string) => HAND_SPELT.test(strip(source));

    expect(scan("(user?.workspaces?.length ?? 0) > 0")).toBe(true);
    expect(scan("user.workspaces.length === 0")).toBe(true);
    expect(scan("// never (user?.workspaces?.length ?? 0) here")).toBe(false);
  });

  it("answers what the hand-spelt versions answered", () => {
    expect(teachesOnPlatform(null)).toBe(false);
    expect(teachesOnPlatform({ workspaces: [] })).toBe(false);
    expect(teachesOnPlatform({ workspaces: undefined } as never)).toBe(false);
    expect(teachesOnPlatform({ workspaces: [{}] } as never)).toBe(true);
  });
});
