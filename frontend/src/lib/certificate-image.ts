import qrcode from "qrcode-generator";

import {
  FIELD_KEYS,
  LINE_HEIGHT,
  canvasFontFamily,
  createMeasure,
  fitText,
  type CertificateValues,
  type DesignPayload,
  type FieldBox,
} from "./certificate-design";

/**
 * The certificate as a PNG the student can keep.
 *
 * ⚠️ NO NEW DEPENDENCY, AND THAT IS THE POINT. A DOM-to-image library would be a
 * second renderer that has to agree with `CertificateArtwork` about where every
 * field sits — and the day it did not, the file somebody attached to a job
 * application would differ from the page an employer opened to verify it. This
 * draws from the SAME pure functions the screen draws from (`fitText`,
 * `LINE_HEIGHT`, the same boxes, the same QR payload), so the two cannot disagree
 * about anything except the pixels a browser puts them in.
 */

/** Wide enough to print at A4 landscape without softening. */
const EXPORT_WIDTH = 2400;

/*
| ⚠️ JPEG RATHER THAN PNG, AND THE QUALITY IS CHOSEN FOR THE QR CODE, not for the
| photograph. The artwork is a photographic sheet, so lossless PNG measured 3.6 MB
| — a keepsake somebody downloads on a phone. What compression must not damage is
| the one part of this picture a MACHINE reads: at this width a QR module is
| **3.98 pixels**, and JPEG's ringing lands exactly on high-contrast edges that
| size.
|
| ⚠️ MEASURED, NOT ASSUMED. `BarcodeDetector` is absent from the browser this was
| checked in, so the file was verified the way a reader actually works: the module
| matrix was regenerated from the same payload and the produced JPEG was sampled at
| every module's CENTRE — **0 of 1369 modules disagreed**, at 595 KB instead of
| 3.6 MB. Lower the quality and re-run that comparison before believing a smaller
| number; the failure mode here is a certificate that looks perfect and scans as
| nothing.
*/
const EXPORT_QUALITY = 0.94;

/*
| ⚠️ THE SAME CLOSED LIST AS `INK` IN `CertificateArtwork.tsx`, resolved to real
| colours rather than to `var()`: a canvas has no element behind it and cannot
| resolve a custom property, exactly as it cannot resolve one in a font stack.
| The value is read off the document so a token changed in `@theme` moves both;
| the literal is the floor for a canvas asked to draw before any stylesheet has
| applied, and is `--color-certificate-ink`'s own value.
*/
const INK_FALLBACK = "#6b0f2b";

const INK_TOKENS: Record<string, string> = {
  "certificate-ink": "--color-certificate-ink",
  ink: "--color-ink",
  "ink-muted": "--color-ink-muted",
};

/**
 * A source the canvas will not refuse to export.
 *
 * ⚠️ A CROSS-ORIGIN IMAGE TAINTS THE CANVAS and `toBlob` then throws — the whole
 * feature, dead, for uploaded designs only. `asset('storage/…')` on the server
 * returns an ABSOLUTE url (`APP_URL`), which in production is this same host and
 * in development is the API on another port. Next already proxies `/storage/*`,
 * so dropping to the path puts the bytes back on our own origin; a url that is
 * already same-origin is left exactly as it is.
 */
function sameOriginSrc(url: string): string {
  try {
    const parsed = new URL(url, window.location.href);

    return parsed.origin === window.location.origin ? url : `${parsed.pathname}${parsed.search}`;
  } catch {
    return url;
  }
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();

    // Asks for a CORS-clean fetch where the server allows one; harmless
    // same-origin, which is what `sameOriginSrc` has just arranged.
    image.crossOrigin = "anonymous";
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error("image"));
    image.src = src;
  });
}

function tokenColour(name: string | undefined): string {
  const property = INK_TOKENS[name ?? ""] ?? INK_TOKENS["certificate-ink"];
  const value = getComputedStyle(document.documentElement).getPropertyValue(property).trim();

  return value === "" ? INK_FALLBACK : value;
}

export interface CertificateImage {
  blob: Blob;
  width: number;
  height: number;
}

export async function renderCertificateImage(
  design: DesignPayload,
  values: CertificateValues,
  fontFamily: string,
): Promise<CertificateImage> {
  const image = await loadImage(sameOriginSrc(design.image_url));

  const aspect =
    image.naturalWidth > 0 && image.naturalHeight > 0
      ? image.naturalWidth / image.naturalHeight
      : 1491 / 1055;

  const width = EXPORT_WIDTH;
  const height = Math.round(width / aspect);

  const canvas = document.createElement("canvas");

  canvas.width = width;
  canvas.height = height;

  const ctx = canvas.getContext("2d");

  if (ctx === null) throw new Error("canvas");

  /*
    ⚠️ WHITE FIRST, BECAUSE JPEG HAS NO ALPHA. A fresh canvas is transparent and
    the encoder writes transparent pixels as BLACK — so any part of the sheet the
    artwork does not cover (a design whose aspect ratio differs by a pixel of
    rounding) would come out as a black band down the edge.
  */
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, width, height);
  ctx.drawImage(image, 0, 0, width, height);
  // Arabic and a Latin certificate number in one drawing.
  ctx.direction = "rtl";

  /*
    ⚠️ SANITISED ONCE, AND USED FOR BOTH THE MEASUREMENT AND THE DRAWING. `ctx.font`
    is a CSS parse that rejects what it cannot understand and silently KEEPS its
    previous value — `10px sans-serif` — so a stack carrying `var(--font-sans)`
    (valid CSS, unresolvable without an element) would measure every width at ten
    pixels, fit nothing, and draw the text at a tenth of its size. That exact
    defect shipped on the screen and was found only by measuring a real browser.
  */
  const family = canvasFontFamily(fontFamily);
  const measure = createMeasure(family);

  for (const key of FIELD_KEYS) {
    const box = design.boxes[key];

    if (box === undefined) continue;

    if (key === "qr") {
      drawQr(ctx, box, values.verifyUrl, width, height);
      continue;
    }

    drawText(ctx, box, values[key], width, height, family, measure);
  }

  const blob = await new Promise<Blob | null>((resolve) =>
    canvas.toBlob(resolve, "image/jpeg", EXPORT_QUALITY),
  );

  if (blob === null) throw new Error("encode");

  return { blob, width, height };
}

function drawText(
  ctx: CanvasRenderingContext2D,
  box: FieldBox,
  text: string,
  width: number,
  height: number,
  fontFamily: string,
  measure: ReturnType<typeof createMeasure>,
): void {
  if (text.trim() === "") return;

  /*
    ⚠️ THE SAME FITTER THE SCREEN USES, at the EXPORT's dimensions rather than the
    screen's. Both are fractions of the image, so the result is the same layout at
    a different scale — which is what makes «the picture matches the page» true by
    construction rather than by inspection.
  */
  const fitted = fitText(text, box, width, height, measure);

  const boxLeft = box.x * width;
  const boxTop = box.y * height;
  const boxWidth = box.w * width;
  const boxHeight = box.h * height;

  ctx.fillStyle = tokenColour(box.color);
  ctx.font = `700 ${fitted.fontSize}px ${fontFamily}`;
  ctx.textBaseline = "middle";

  const align = box.align ?? "center";

  ctx.textAlign = align === "center" ? "center" : align === "start" ? "right" : "left";

  const anchorX =
    align === "center" ? boxLeft + boxWidth / 2 : align === "start" ? boxLeft + boxWidth : boxLeft;

  const lineHeight = fitted.fontSize * LINE_HEIGHT;
  const firstCentre = boxTop + boxHeight / 2 - ((fitted.lines.length - 1) * lineHeight) / 2;

  fitted.lines.forEach((line, index) => {
    ctx.fillText(line, anchorX, firstCentre + index * lineHeight);
  });
}

/*
| ⚠️ Black on white, deliberately, exactly as on the screen: a QR is a machine
| target photographed off paper and scanners fail on an inverted code, so it must
| not follow the viewer's theme. The white plate is the quiet zone every reader
| needs to find the code at all.
*/
function drawQr(
  ctx: CanvasRenderingContext2D,
  box: FieldBox,
  url: string,
  width: number,
  height: number,
): void {
  if (url === "") return;

  let code;

  try {
    code = qrcode(0, "M");
    code.addData(url);
    code.make();
  } catch {
    // A payload too long for any version is not worth a blank square.
    return;
  }

  const count = code.getModuleCount();
  const span = count + 4;

  const boxLeft = box.x * width;
  const boxTop = box.y * height;
  const boxWidth = box.w * width;
  const boxHeight = box.h * height;

  // Square, and centred in whatever rectangle the design gave it: a stretched
  // code is a code no camera reads.
  const side = Math.min(boxWidth, boxHeight);
  const left = boxLeft + (boxWidth - side) / 2;
  const top = boxTop + (boxHeight - side) / 2;
  const cell = side / span;

  ctx.fillStyle = "#ffffff";
  ctx.fillRect(left, top, side, side);
  ctx.fillStyle = "#000000";

  for (let row = 0; row < count; row += 1) {
    for (let column = 0; column < count; column += 1) {
      if (!code.isDark(row, column)) continue;

      ctx.fillRect(
        left + (column + 2) * cell,
        top + (row + 2) * cell,
        // A hair of overlap: a fractional cell edge leaves hairlines between
        // modules, and a scanner reads those as damage.
        Math.ceil(cell) + 0.5,
        Math.ceil(cell) + 0.5,
      );
    }
  }
}

/** Hands the file to the browser and cleans up after it. */
export function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Revoked on the next tick: revoking synchronously can race the download in
  // some browsers, and an object url held for the life of the tab is a leak of
  // the whole image.
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
