/**
 * An equation on the board (story 6): LaTeX (with mhchem's `\ce{}` for
 * chemistry) drawn by MathJax (mathjax-full 3.2.2, Apache-2.0, owner-approved
 * 2026-10-03) as a self-contained SVG — its glyphs are paths, so no font is
 * fetched — then rasterised to a PNG the board stores like any picture.
 *
 * Loaded only when an equation is drawn: never in the board's first load.
 *
 * Packages are a closed list: no `html` (\href, \class, \style) and no
 * `require` — an equation cannot reach outside itself.
 */

type Converter = (latex: string, display: boolean) => string;

let converter: Promise<Converter> | null = null;

function loadConverter(): Promise<Converter> {
  converter ??= (async () => {
    const [{ mathjax }, { TeX }, { SVG }, { liteAdaptor }, { RegisterHTMLHandler }] = await Promise.all([
      import("mathjax-full/js/mathjax.js"),
      import("mathjax-full/js/input/tex.js"),
      import("mathjax-full/js/output/svg.js"),
      import("mathjax-full/js/adaptors/liteAdaptor.js"),
      import("mathjax-full/js/handlers/html.js"),
      import("mathjax-full/js/input/tex/base/BaseConfiguration.js"),
      import("mathjax-full/js/input/tex/ams/AmsConfiguration.js"),
      import("mathjax-full/js/input/tex/mhchem/MhchemConfiguration.js"),
      import("mathjax-full/js/input/tex/color/ColorConfiguration.js"),
      import("mathjax-full/js/input/tex/cancel/CancelConfiguration.js"),
      import("mathjax-full/js/input/tex/boldsymbol/BoldsymbolConfiguration.js"),
    ]);
    const adaptor = liteAdaptor();
    RegisterHTMLHandler(adaptor);
    const doc = mathjax.document("", {
      InputJax: new TeX({ packages: ["base", "ams", "mhchem", "color", "cancel", "boldsymbol"] }),
      OutputJax: new SVG({ fontCache: "none" }),
    });
    return (latex: string, display: boolean) => {
      const node = doc.convert(latex, { display });
      return adaptor.innerHTML(node);
    };
  })();
  converter.catch(() => (converter = null)); // a failed load is tried again next time
  return converter;
}

/** The equation as SVG markup, `currentColor` throughout; MathJax's own error box on bad LaTeX. */
export async function mathSvg(latex: string, display: boolean): Promise<string> {
  return (await loadConverter())(latex, display);
}

/** One `ex` in board units at the size an equation is drawn (MathJax's ex is ~0.442em). */
const EX = 48 * 0.442;
const SCALE = 2;
const MAX_SIDE = 2560;

/**
 * The equation as a PNG in `colour` (the pen's, so it reads on a blackboard
 * too), transparent around it. Returns its size in the board's units.
 */
export async function renderMath(latex: string, display: boolean, colour: string): Promise<{ blob: Blob; width: number; height: number }> {
  const markup = await mathSvg(latex, display);
  const svg = new DOMParser().parseFromString(markup, "image/svg+xml").documentElement;
  const exWidth = parseFloat(svg.getAttribute("width") ?? "0");
  const exHeight = parseFloat(svg.getAttribute("height") ?? "0");
  if (!(exWidth > 0) || !(exHeight > 0) || svg.querySelector("[data-mjx-error]")) throw new MathError();

  const width = exWidth * EX;
  const height = exHeight * EX;
  const scale = Math.min(SCALE, MAX_SIDE / Math.max(width, height));
  svg.setAttribute("width", String(Math.ceil(width * scale)));
  svg.setAttribute("height", String(Math.ceil(height * scale)));
  svg.setAttribute("color", colour);
  svg.setAttribute("style", `color:${colour}`);

  const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svg)], { type: "image/svg+xml" }));
  try {
    const image = await new Promise<HTMLImageElement>((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new MathError());
      img.src = url;
    });
    const canvas = document.createElement("canvas");
    canvas.width = Math.ceil(width * scale);
    canvas.height = Math.ceil(height * scale);
    canvas.getContext("2d")?.drawImage(image, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/png"));
    if (!blob) throw new MathError();
    return { blob, width, height };
  } finally {
    URL.revokeObjectURL(url);
  }
}

/** The LaTeX does not draw (a typo, an unknown command). */
export class MathError extends Error {
  constructor() {
    super("math-error");
    this.name = "MathError";
  }
}
