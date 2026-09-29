import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

import { STAGGER_CLASS, staggerStyle } from "./stagger";

/*
| The stagger's numbers live in `globals.css` and nowhere else, and that file is
| UNLAYERED — a rule there beats every utility (docs/gotchas/frontend.md). So the
| guard reads the CSS itself: the class must exist, must set only `animation-*`,
| must cap its delay, and the reduced-motion block must still zero delays.
*/

const CSS = readFileSync(join(process.cwd(), "src", "app", "globals.css"), "utf8").replace(
  /\/\*[\s\S]*?\*\//g,
  "",
);

function block(selector: string): string {
  const at = CSS.indexOf(`${selector} {`);

  expect(at, `${selector} is missing from globals.css`).toBeGreaterThanOrEqual(0);

  return CSS.slice(CSS.indexOf("{", at) + 1, CSS.indexOf("}", at));
}

describe("staggerStyle", () => {
  it("hands the index to CSS and nothing else", () => {
    expect(staggerStyle(3)).toEqual({ "--stagger-i": 3 });
  });

  it("never passes a negative or fractional index", () => {
    expect(staggerStyle(-2)).toEqual({ "--stagger-i": 0 });
    expect(staggerStyle(2.7)).toEqual({ "--stagger-i": 2 });
  });
});

describe(`.${STAGGER_CLASS} in globals.css`, () => {
  it("sets only animation properties — an unlayered rule may not fight a utility", () => {
    const properties = block(`.${STAGGER_CLASS}`)
      .split(";")
      .map((declaration) => declaration.trim())
      .filter(Boolean)
      .map((declaration) => declaration.split(":")[0].trim());

    expect(properties.length).toBeGreaterThan(0);
    for (const property of properties) expect(property).toMatch(/^animation(-|$)/);
  });

  it("caps the delay, so a long list never arrives late", () => {
    expect(block(`.${STAGGER_CLASS}`)).toMatch(/min\(var\(--stagger-i, 0\), 8\)/);
  });

  it("holds its start state through the delay (`both`)", () => {
    expect(block(`.${STAGGER_CLASS}`)).toMatch(/\bboth\b/);
  });

  it("is zeroed by the reduced-motion block, delay included", () => {
    const reduced = CSS.slice(CSS.indexOf("@media (prefers-reduced-motion: reduce)"));

    expect(reduced).toMatch(/animation-delay:\s*0ms !important/);
    expect(reduced).toMatch(/animation-duration:\s*0\.01ms !important/);
  });
});
