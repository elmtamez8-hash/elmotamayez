import { describe, expect, it } from "vitest";
import {
  canvasFontFamily,
  clampBox,
  fitText,
  boxToStyle,
  LINE_HEIGHT,
  type FieldBox,
  type Measure,
} from "./certificate-design";

/*
| القياسُ هنا صناعيٌّ وخطّيٌّ عمداً: `jsdom` بلا `canvas`، والمطلوبُ إثباتُ أنّ
| المنطقَ لا يتخطّى عرضاً معلوماً — لا أن يُعادَ رسمُ محرَك الخطوط.
*/
const measure: Measure = (text, fontSizePx) => text.length * fontSizePx * 0.62;

// أبعادُ القالبِ المشحونِ الحقيقيّة، وصندوقُ الاسمِ من السجلّ.
const WIDTH = 1491;
const HEIGHT = 1055;

const nameBox: FieldBox = {
  x: 0.29,
  y: 0.41,
  w: 0.42,
  h: 0.055,
  align: "center",
  max_font: 0.042,
  min_font: 0.02,
  color: "certificate-ink",
};

function repeat(unit: string, length: number): string {
  // Words, not one unbroken run — a name is words, and wrapping needs them.
  let out = "";

  while (out.length < length) out += `${unit} `;

  return out.slice(0, length).trim();
}

const SAMPLES: Record<string, (n: number) => string> = {
  عربي: (n) => repeat("محمد", n),
  latin: (n) => repeat("Abdul", n),
  مختلط: (n) => repeat("Ali علي", n),
};

describe("clampBox", () => {
  it("keeps a box inside the image", () => {
    const out = clampBox({ ...nameBox, x: 1.4, y: -0.3, w: 0.42, h: 0.055 });

    expect(out.x).toBeGreaterThanOrEqual(0);
    expect(out.y).toBeGreaterThanOrEqual(0);
    expect(out.x + out.w).toBeLessThanOrEqual(1);
    expect(out.y + out.h).toBeLessThanOrEqual(1);
  });

  it("shrinks a box wider than the image rather than letting it hang off", () => {
    const out = clampBox({ ...nameBox, x: 0.2, w: 3 });

    expect(out.w).toBeLessThanOrEqual(1);
    expect(out.x + out.w).toBeLessThanOrEqual(1);
  });

  it("refuses a non-finite value instead of producing NaN geometry", () => {
    const out = clampBox({ ...nameBox, x: Number.NaN, w: Number.POSITIVE_INFINITY });

    expect(Number.isFinite(out.x)).toBe(true);
    expect(Number.isFinite(out.w)).toBe(true);
  });
});

describe("boxToStyle", () => {
  it("turns fractions into pixels of the image it was given", () => {
    expect(boxToStyle(nameBox, WIDTH, HEIGHT)).toEqual({
      left: 0.29 * WIDTH,
      top: 0.41 * HEIGHT,
      width: 0.42 * WIDTH,
      height: 0.055 * HEIGHT,
    });
  });
});

describe("fitText — SC-002", () => {
  const lengths = [3, 12, 25, 40, 60];

  for (const [script, make] of Object.entries(SAMPLES)) {
    for (const length of lengths) {
      it(`keeps ${length} ${script} characters inside the box`, () => {
        const text = make(length);
        const fitted = fitText(text, nameBox, WIDTH, HEIGHT, measure);
        const boxWidth = nameBox.w * WIDTH;
        const boxHeight = nameBox.h * HEIGHT;

        // Nothing sticks out sideways...
        for (const line of fitted.lines) {
          expect(measure(line, fitted.fontSize)).toBeLessThanOrEqual(boxWidth + 0.001);
        }

        // ...nor downwards.
        expect(fitted.lines.length * fitted.fontSize * LINE_HEIGHT)
          .toBeLessThanOrEqual(boxHeight + 0.001);

        // And every character survives: no clipping, no ellipsis.
        expect(fitted.lines.join(" ")).toBe(text);
        expect(fitted.lines.join("")).not.toContain("…");
      });
    }
  }

  it("never enlarges a short name past max_font", () => {
    const fitted = fitText("علي", nameBox, WIDTH, HEIGHT, measure);

    expect(fitted.fontSize).toBeLessThanOrEqual(nameBox.max_font! * HEIGHT + 0.001);
    expect(fitted.lines).toEqual(["علي"]);
  });

  it("uses the whole allowance for a short name rather than shrinking it", () => {
    const fitted = fitText("علي", nameBox, WIDTH, HEIGHT, measure);

    expect(fitted.fontSize).toBeCloseTo(nameBox.max_font! * HEIGHT, 5);
  });

  it("wraps onto a second line instead of dropping below min_font", () => {
    const long = repeat("عبدالرحمن", 60);
    const oneLineAtMin = measure(long, nameBox.min_font! * HEIGHT);

    // The premise: this text cannot fit on one line at the smallest allowed size.
    expect(oneLineAtMin).toBeGreaterThan(nameBox.w * WIDTH);

    const fitted = fitText(long, nameBox, WIDTH, HEIGHT, measure);

    expect(fitted.lines.length).toBeGreaterThan(1);
    expect(fitted.fontSize).toBeGreaterThanOrEqual(nameBox.min_font! * HEIGHT);
  });

  it("shrinks below min_font rather than clipping a name that cannot wrap", () => {
    const unbroken = "ا".repeat(200);
    const fitted = fitText(unbroken, nameBox, WIDTH, HEIGHT, measure);

    expect(fitted.lines).toEqual([unbroken]);
    expect(measure(unbroken, fitted.fontSize)).toBeLessThanOrEqual(nameBox.w * WIDTH + 0.001);
  });

  it("draws nothing for an empty value", () => {
    expect(fitText("   ", nameBox, WIDTH, HEIGHT, measure).lines).toEqual([]);
  });
});

/*
| ⚠️ THE ONE THING `jsdom` CANNOT SEE, GUARDED AS A STRING FUNCTION.
|
| Every test above measures through the fallback heuristic, because jsdom has no
| canvas — so a canvas font string that a real browser REJECTS is green here and
| catastrophic in production: `ctx.font` keeps its previous value on an invalid
| assignment, which is `10px sans-serif`, so every width is measured at ten pixels
| whatever size was asked for, every candidate «fits», and `fitText` hands back
| `max_font` for a name twice too wide. Measured live on 2026-09-06: a
| 40-character name overflowed its box by 105px on the public verification page
| while this whole file was green. `var(--font-sans)` was the reason — valid CSS,
| unparseable by a canvas, which has no element and therefore no custom properties.
*/
describe("canvasFontFamily", () => {
  it("drops a custom property a canvas cannot resolve", () => {
    expect(canvasFontFamily("var(--font-sans), Cairo, sans-serif")).toBe("Cairo, sans-serif");
  });

  it("never returns an empty stack", () => {
    expect(canvasFontFamily("var(--font-sans)")).toBe("sans-serif");
    expect(canvasFontFamily("")).toBe("sans-serif");
  });

  it("leaves a resolved stack alone", () => {
    expect(canvasFontFamily('"Cairo", sans-serif')).toBe('"Cairo", sans-serif');
  });
});
