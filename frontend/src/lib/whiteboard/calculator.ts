/**
 * The board's scientific calculator (owner-approved 2026-10-03: «زي كاسيو»,
 * built on ready parts). MathLive writes the expression as LaTeX, and the
 * Compute Engine (@cortex-js/compute-engine 0.147.0, MIT, same authors) works
 * it out: EXACT first (½, 2√2, √2⁄2), and a decimal beside it — the S⇔D key.
 *
 * Loaded only when the calculator opens: the engine is ~1.1 MB gzipped.
 * Its `compile()` builds code with `new Function`, which the production CSP
 * forbids — nothing here calls it (measured: `evaluate`/`N` never do).
 */

export type AngleUnit = "deg" | "rad";

export type CalcResult =
  | { ok: true; exact: string | null; decimal: string }
  /** syntax: the expression is not finished · math: no real answer (÷0, √ of a negative). */
  | { ok: false; error: "syntax" | "math" };

type Engine = InstanceType<typeof import("@cortex-js/compute-engine").ComputeEngine>;

let engine: Promise<Engine> | null = null;

function loadEngine(): Promise<Engine> {
  engine ??= import("@cortex-js/compute-engine").then(({ ComputeEngine }) => new ComputeEngine());
  engine.catch(() => (engine = null)); // a failed load is tried again next time
  return engine;
}

/** A function left unevaluated (sin 1 in radians) has no exact form worth showing. */
const UNEVALUATED = /\\(sin|cos|tan|cot|sec|csc|arc|log|ln|operatorname|error)|\\tilde\\infty|\\imaginaryI/;

export async function calculate(latex: string, angle: AngleUnit): Promise<CalcResult> {
  const ce = await loadEngine();
  ce.angularUnit = angle;
  const expression = ce.parse(latex);
  if (!expression.isValid || latex.trim() === "") return { ok: false, error: "syntax" };

  const exact = expression.evaluate();
  const value = exact.N();
  const re = value.re;
  const im = value.im;
  if (typeof re !== "number" || !Number.isFinite(re) || (typeof im === "number" && im !== 0)) return { ok: false, error: "math" };

  const decimal = formatDecimal(re);
  const exactLatex = exact.latex.replace(/\\,/g, "");
  const showExact = !UNEVALUATED.test(exactLatex) && exactLatex !== decimal && exactLatex.length <= 80;

  return { ok: true, exact: showExact ? exactLatex : null, decimal };
}

/**
 * Ten significant digits, as a Casio shows them; very large or very small
 * numbers in scientific form (`1.5\times10^{12}`).
 */
export function formatDecimal(n: number): string {
  if (n === 0) return "0";
  const size = Math.abs(n);
  if (size >= 1e10 || size < 1e-9) {
    const [mantissa, exponent] = n.toExponential(9).split("e");
    return `${trimZeros(mantissa)}\\times10^{${Number(exponent)}}`;
  }
  return trimZeros(n.toPrecision(10));
}

function trimZeros(text: string): string {
  return text.includes(".") ? text.replace(/\.?0+$/, "") : text;
}
