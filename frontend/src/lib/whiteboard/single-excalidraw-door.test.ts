import { readdirSync, readFileSync } from "node:fs";
import { join, relative, sep } from "node:path";

import { describe, expect, it } from "vitest";

/*
| ONE DOOR TO EXCALIDRAW, AND THE HEAVY LIBRARIES ONLY BEHIND `dynamic()` (spec 039).
|
| `@excalidraw/excalidraw` is pinned at 0.18.1 and its `master` already renames
| `scrollToContent` and reshapes `setActiveTool`. Every call goes through
| `lib/whiteboard/excalidraw-api.ts` so an upgrade touches one file; the canvas
| component imports only `<Excalidraw>` and its CSS. ESLint is not configured in
| this tree, so the rule is this scan.
|
| ⚠️ AND MathJax, MathLive and three load ONLY when their editor or activity opens
| (SC-009): a static import from any shared file would put megabytes in the first
| load of every board.
|
| ⚠️ AND NO RAW `fetch(` UNDER lib/whiteboard: the session's headers (token, device,
| workspace) are built once, in `lib/api.ts` (`api.blob`, `deleteKeepalive`). The
| one exception reads PUBLIC font files that need no session.
|
| Comments are stripped first (see `no-hand-rolled-sign-in.test.ts`); type-only
| imports are erased at build and allowed anywhere.
*/
const SRC = join(process.cwd(), "src");

function strip(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:])\/\/.*$/gm, "$1");
}

function productionFiles(dir: string, found: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      productionFiles(path, found);
      continue;
    }
    if (/\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)) found.push(path);
  }
  return found;
}

const files = productionFiles(SRC).map((path) => ({
  path: relative(SRC, path).split(sep).join("/"),
  code: strip(readFileSync(path, "utf8")),
}));

/** Value imports (not `import type`) of a module, as written in source. */
function valueImportsOf(code: string, pattern: RegExp): string[] {
  return [...code.matchAll(/import\s+(?!type\b)([^;]*?)\s+from\s+["']([^"']+)["']/g)]
    .filter((match) => pattern.test(match[2]))
    .map((match) => match[0]);
}

describe("the whiteboard's one door to Excalidraw", () => {
  it("is scanning real files", () => {
    expect(files.some((file) => file.path === "lib/whiteboard/excalidraw-api.ts")).toBe(true);
  });

  it("lets only excalidraw-api.ts call the library, and BoardCanvas mount the component", () => {
    const offenders: string[] = [];

    for (const file of files) {
      const imports = valueImportsOf(file.code, /^@excalidraw\/excalidraw(\/|$)/);
      if (imports.length === 0) continue;
      if (file.path === "lib/whiteboard/excalidraw-api.ts") continue;
      if (file.path === "components/whiteboard/BoardCanvas.tsx") {
        const extra = imports.filter((line) => !/import\s*\{\s*Excalidraw\s*\}\s*from\s*["']@excalidraw\/excalidraw["']/.test(line));
        if (extra.length === 0) continue;
        offenders.push(...extra.map((line) => `${file.path}: ${line}`));
        continue;
      }
      offenders.push(...imports.map((line) => `${file.path}: ${line}`));
    }

    expect(offenders).toEqual([]);
  });

  it("never imports MathJax, MathLive or three statically", () => {
    const offenders = files.flatMap((file) =>
      valueImportsOf(file.code, /^(mathlive|mathjax|@mathjax\/|three)(\/|$)/).map((line) => `${file.path}: ${line}`),
    );

    expect(offenders).toEqual([]);
  });

  it("builds no request by hand under lib/whiteboard", () => {
    const offenders = files
      .filter((file) => file.path.startsWith("lib/whiteboard/") && file.path !== "lib/whiteboard/arabic-font.ts")
      .filter((file) => /(?<![.\w$])fetch\s*\(/.test(file.code))
      .map((file) => file.path);

    expect(offenders).toEqual([]);
  });
});
