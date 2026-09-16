import { readdirSync, readFileSync } from "node:fs";
import { join, sep } from "node:path";

import { describe, expect, it } from "vitest";

/*
| ONE SPELLING OF «THIS TAB NOW HOLDS A SESSION» — reported 2026-09-16.
|
| `AuthProvider` owns `user`, and the header, the sidebar and every `useAuth()`
| reader draw from it. A form that writes the token straight into `localStorage`
| gets a working API client and a chrome that still says «سجّل الدخول», until a
| full reload remounts the provider and it exchanges that token for a profile.
| THREE forms did exactly that — student, parent and teacher — each correct on
| its own, each invisible to `tsc`, to pest and to every component test, because
| the defect lives in the seam between the form and the provider.
|
| ⚠️ AND THE OBVIOUS FIX IS WHAT MAKES THIS FILE NECESSARY RATHER THAN OPTIONAL.
| Adding `setUser` beside the hand-written `setToken` leaves TWO spellings of one
| rule, which is the shape this repository has paid for over and over. The token
| helpers stay exported because `lib/auth-context.tsx` is built out of them; what
| may not happen is a SECOND caller. So the rule is a place, not a name.
|
| ⚠️ COMMENTS ARE STRIPPED FIRST, for the reason `no-native-dialogs.test.ts` and
| `theme-tokens.test.ts` both had to learn: every one of the three fixes is
| explained in prose beside the line it removed, and a guard that goes red over
| its own explanation teaches people to delete the explanation.
|
| The helpers below are deliberately NOT shared with that sibling: a module under
| `lib/` importable by production code, existing only for two scanners, is a
| worse trade than thirty duplicated lines that each file can be read without.
*/
const ROOT = process.cwd();

/** The two writers, called — never `foo.setToken(...)`, which is somebody else's. */
const HAND_ROLLED = /(?<![.\w$])(setToken|setSessionUuid)\s*\(/;

/** Only `lib/api.ts` may define them and only `lib/auth-context.tsx` may call them. */
const ALLOWED = ["lib/api.ts", "lib/auth-context.tsx"];

/* Comments only. `no-native-dialogs.test.ts` also blanks string literals,
   because an injection fixture carries `alert(1)` as DATA; no fixture in
   this tree carries a call to the token writers inside a string. */
function strip(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|[^:])\/\/.*$/gm, "$1");
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
    // Compared with forward slashes so the list reads the same on either OS.
    if (ALLOWED.some((allowed) => path.split(sep).join("/").endsWith(allowed))) continue;

    found.push(path);
  }

  return found;
}

export function findHandRolledSignIns(files: string[]): string[] {
  const hits: string[] = [];

  for (const file of files) {
    strip(readFileSync(file, "utf8"))
      .split("\n")
      .forEach((line, index) => {
        if (HAND_ROLLED.test(line)) hits.push(`${file.replace(ROOT, "")}:${index + 1}`);
      });
  }

  return hits;
}

describe("no hand-rolled sign-in", () => {
  it("only the auth context writes the token", () => {
    const hits = findHandRolledSignIns(productionFiles(join(ROOT, "src")));

    expect(
      hits,
      `Token written outside AuthProvider — call \`adoptSession()\` from \`useAuth()\` instead:\n  ${hits.join("\n  ")}`,
    ).toEqual([]);
  });

  /*
  | ⛔ THE SCANNER'S OWN TESTS. A regex that matches nothing passes the case
  | above for ever, over a tree full of the thing it forbids.
  */
  const scan = (source: string) => HAND_ROLLED.test(strip(source));

  it("catches a real call", () => {
    expect(scan("      setToken(token);")).toBe(true);
    expect(scan("setSessionUuid(session_uuid);")).toBe(true);
    expect(scan("if (result.token) setToken(result.token);")).toBe(true);
  });

  it("a comment explaining the rule is not a violation of it", () => {
    expect(scan("// THROUGH THE PROVIDER, NEVER setToken(...) BY HAND")).toBe(false);
    expect(scan("/* the forms called setToken(token) and never told the provider */")).toBe(false);
  });

  it("a method on something else is not the writer", () => {
    expect(scan("client.setToken(value)")).toBe(false);
    expect(scan("this.setSessionUuid(uuid)")).toBe(false);
  });
});
