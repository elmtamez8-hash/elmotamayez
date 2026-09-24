import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/*
| A course PICKER asks for every course, not the first page of fifteen.
|
| `GET /courses` paginates at 15 unless told otherwise (`CourseController`
| clamps `per_page` to 1..200). Five teacher screens — announcements, assistants,
| unlock rules, plans and sessions — built a picker from the bare call, so a
| teacher's sixteenth course could never be chosen and nothing said why.
|
| A source scan rather than five page tests, for the reason `no-native-dialogs`
| gives: the defect is ONE spelling repeated, and the next screen to write it is
| the one no page test exists for yet. Comments and test files are skipped.
*/
const ROOT = join(process.cwd(), "src");

function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);

    if (entry.isDirectory()) return sources(path);
    if (!/\.tsx?$/.test(entry.name) || /\.test\.tsx?$/.test(entry.name)) return [];

    return [path];
  });
}

function stripComments(code: string): string {
  return code.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:])\/\/.*$/gm, "$1");
}

describe("course pickers", () => {
  it("never read the paginated course index without per_page", () => {
    const offenders = sources(ROOT).filter((file) =>
      /\.get(?:<[\s\S]*?>)?\(\s*["'`]\/courses["'`]\s*\)/.test(stripComments(readFileSync(file, "utf8"))),
    );

    expect(offenders).toEqual([]);
  });
});
