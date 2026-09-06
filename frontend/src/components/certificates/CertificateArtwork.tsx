"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import qrcode from "qrcode-generator";
import {
  FIELD_KEYS,
  LINE_HEIGHT,
  boxToStyle,
  createMeasure,
  fitText,
  type CertificateValues,
  type DesignPayload,
  type FieldBox,
  type FieldKey,
  type Measure,
} from "@/lib/certificate-design";

/*
| ONE component draws the certificate, and it is used in THREE places: the public
| verify page, the gallery preview and the position editor. A second drawing path
| would let the preview disagree with the certificate it previews, which is the
| worst thing a position editor can do to a teacher.
|
| ⚠️ IT MUST NOT ASSUME IT IS ON THE VERIFY PAGE: no fetching, no routing, no
| valid/invalid verdict. It is handed a design and six values.
*/

/*
| ⚠️ A COLOUR NAME ARRIVES FROM THE SERVER, SO IT IS MAPPED THROUGH A CLOSED LIST.
| Tailwind emits no rule for a token `@theme` never defined, and CSS resolves an
| unknown `var()` to nothing — either way the text is INVISIBLE with no error
| anywhere, which this tree has shipped four times. An unrecognised name falls
| back to the certificate ink rather than to silence.
*/
const INK: Record<string, string> = {
  "certificate-ink": "var(--color-certificate-ink)",
  ink: "var(--color-ink)",
  "ink-muted": "var(--color-ink-muted)",
};

const DEFAULT_INK = INK["certificate-ink"];

/** Fallback shape until the artwork reports its own (FR-047). */
const FALLBACK_ASPECT = 1491 / 1055;

/*
| What the geometry is computed against before the element has been measured.
| jsdom reports 0 for every box and has no ResizeObserver, and a font size of 0
| renders nothing — so a component test would be asserting on an empty sheet.
*/
const NOMINAL_WIDTH = 1000;

export interface CertificateArtworkProps {
  design: DesignPayload;
  values: CertificateValues;
  /** Overrides the accessible name — a gallery preview is a sample, not a certificate. */
  ariaLabel?: string;
  /**
   * The artwork's TRUE shape, once its image has loaded.
   *
   * ⚠️ Only this component ever learns it — the payload carries no dimensions —
   * and the print sheet needs it: a landscape certificate printed portrait loses
   * a third of itself to the margin (`FR-047`). Reported rather than exposed as
   * state, so nothing outside has to measure an image a second time.
   */
  onAspect?: (aspect: number) => void;
}

export function CertificateArtwork({ design, values, ariaLabel, onAspect }: CertificateArtworkProps) {
  const frame = useRef<HTMLDivElement>(null);
  const [measured, setMeasured] = useState(0);
  const [aspect, setAspect] = useState(FALLBACK_ASPECT);
  const [imageFailed, setImageFailed] = useState(false);
  /*
    ⚠️ THE RESOLVED FAMILY, READ OFF THE DOM — never the `var(--font-sans)` token.
    A canvas parses the font shorthand with no element behind it, so a custom
    property in the stack makes the whole assignment invalid, the context keeps
    `10px sans-serif`, and every width comes back measured at ten pixels: the
    fitter then finds that everything fits at `max_font` and a long name runs
    straight out of its box. Measured live on 2026-09-06 at 105px of overflow.
  */
  const [fontFamily, setFontFamily] = useState("sans-serif");

  useEffect(() => {
    const element = frame.current;

    if (element === null) return;

    const read = () => setMeasured(element.clientWidth);

    read();
    setFontFamily(getComputedStyle(element).fontFamily || "sans-serif");

    if (typeof ResizeObserver === "undefined") return;

    const observer = new ResizeObserver(read);

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  const width = measured > 0 ? measured : NOMINAL_WIDTH;
  const height = width / aspect;
  const measure = useMemo(() => createMeasure(fontFamily), [fontFamily]);

  return (
    <div
      ref={frame}
      role="img"
      aria-label={ariaLabel ?? `شهادة ${values.student}`}
      data-image-failed={imageFailed ? "true" : undefined}
      className="relative w-full overflow-hidden rounded-lg border border-line bg-surface-raised"
      style={{ aspectRatio: String(aspect) }}
    >
      {/*
        ⚠️ A PLAIN <img>, NEVER `next/image`. The uploaded-design story feeds this
        a user-supplied file, and one such call site revives the `sharp` advisory
        that CLAUDE.md records as closed BY CALL-SITE DISCIPLINE — that note is
        true only while no user image reaches the optimiser.

        ⚠️ And a failed load is a STATE, not nothing: the facts stay drawn over a
        plain sheet rather than leaving an empty frame, which reads as a broken
        certificate to the one person least able to tell the difference.
      */}
      {!imageFailed && (
        <img
          src={design.image_url}
          alt=""
          aria-hidden="true"
          className="absolute inset-0 h-full w-full object-fill"
          onLoad={(event) => {
            const image = event.currentTarget;

            if (image.naturalWidth > 0 && image.naturalHeight > 0) {
              setAspect(image.naturalWidth / image.naturalHeight);
              onAspect?.(image.naturalWidth / image.naturalHeight);
            }
          }}
          onError={() => setImageFailed(true)}
        />
      )}

      {FIELD_KEYS.map((key) =>
        key === "qr" ? (
          <QrBox
            key={key}
            box={design.boxes.qr}
            url={values.verifyUrl}
            width={width}
            height={height}
          />
        ) : (
          <TextBox
            key={key}
            field={key}
            box={design.boxes[key]}
            text={values[key]}
            width={width}
            height={height}
            measure={measure}
          />
        ),
      )}
    </div>
  );
}

function TextBox({
  field,
  box,
  text,
  width,
  height,
  measure,
}: {
  field: Exclude<FieldKey, "qr">;
  box: FieldBox | undefined;
  text: string;
  width: number;
  height: number;
  measure: Measure;
}) {
  if (box === undefined || text.trim() === "") return null;

  const style = boxToStyle(box, width, height);
  /*
    ⚠️ The fitting is done by the pure module and never here. jsdom does no
    layout, so anything computed inside a component is beyond `npm test` — and
    this is exactly where the defect lives (a name wider than its frame).
  */
  const fitted = fitText(text, box, width, height, measure);

  return (
    <div
      data-field={field}
      className="absolute flex flex-col justify-center"
      style={{
        left: `${(style.left / width) * 100}%`,
        top: `${(style.top / height) * 100}%`,
        width: `${box.w * 100}%`,
        height: `${box.h * 100}%`,
        fontSize: `${fitted.fontSize}px`,
        lineHeight: LINE_HEIGHT,
        color: INK[box.color ?? ""] ?? DEFAULT_INK,
        textAlign: box.align === "start" ? "start" : box.align === "end" ? "end" : "center",
        fontWeight: 700,
      }}
    >
      {/*
        ⚠️ `whitespace-nowrap` IS LOAD-BEARING, NOT TIDINESS. The fitter has
        already decided where the line breaks are and sized the text so each one
        fits; letting the browser break a second time — which it does to any long
        certificate number — puts two lines in a box measured for one and the
        text spills straight out of the frame, which is the exact failure this
        whole module exists to prevent.
      */}
      {fitted.lines.map((line, index) => (
        <span key={index} className="block whitespace-nowrap">
          {line}
        </span>
      ))}
    </div>
  );
}

/*
| The code carries the ABSOLUTE verify URL of this certificate (FR-028): a phone
| camera has no base to resolve a relative path against.
|
| ⚠️ Black on white, and that is not a theme colour that slipped through. This is
| a machine-readable target photographed off PAPER, and scanners fail on an
| inverted code — so it must not follow the viewer's theme, for the same reason
| `--color-certificate-ink` is absent from the dark block. The white plate around
| it is the quiet zone every reader needs to find the code at all.
*/
function QrBox({
  box,
  url,
  width,
  height,
}: {
  box: FieldBox | undefined;
  url: string;
  width: number;
  height: number;
}) {
  const modules = useMemo(() => {
    if (url === "") return null;

    try {
      // 0 = the smallest version that fits; "M" survives a fold and a photocopy.
      const code = qrcode(0, "M");

      code.addData(url);
      code.make();

      const count = code.getModuleCount();
      const cells: Array<[number, number]> = [];

      for (let row = 0; row < count; row += 1) {
        for (let column = 0; column < count; column += 1) {
          if (code.isDark(row, column)) cells.push([row, column]);
        }
      }

      return { count, cells };
    } catch {
      // A URL too long for any version is not worth a blank page.
      return null;
    }
  }, [url]);

  if (box === undefined || modules === null) return null;

  const style = boxToStyle(box, width, height);
  const span = modules.count + 4;

  return (
    <div
      data-field="qr"
      className="absolute"
      style={{
        left: `${(style.left / width) * 100}%`,
        top: `${(style.top / height) * 100}%`,
        width: `${box.w * 100}%`,
        height: `${box.h * 100}%`,
      }}
    >
      <svg
        viewBox={`-2 -2 ${span} ${span}`}
        className="h-full w-full"
        role="img"
        aria-label={`رمز التحقّق: ${url}`}
        shapeRendering="crispEdges"
      >
        <rect x={-2} y={-2} width={span} height={span} fill="#ffffff" />
        {modules.cells.map(([row, column]) => (
          <rect key={`${row}-${column}`} x={column} y={row} width={1} height={1} fill="#000000" />
        ))}
      </svg>
    </div>
  );
}
