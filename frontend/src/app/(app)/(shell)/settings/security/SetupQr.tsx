"use client";

import { useMemo } from "react";

import qrcode from "qrcode-generator";

/**
 * The `otpauth://` URI as a QR code — the path that needs no typing at all.
 *
 * ⚠️ IT WAS MISSING, AND THE TWO PATHS THAT WERE HERE BOTH FAIL ON A DESKTOP.
 * Reported 2026-09-22 while walking ٠٢٧ · T074. The screen offered a base32 key
 * to type and an `otpauth://` link to tap — and the link opens nothing on a
 * computer, so the only way through was reading a 32-character secret off one
 * screen and typing it into a phone. A camera reads it in a second instead.
 *
 * `qrcode-generator` is already a dependency: `CertificateArtwork` renders a
 * verification code with it, and this borrows that idiom whole — version 0 (the
 * smallest that fits), level "M", `crispEdges`, and a quiet zone of two modules,
 * which the format requires and without which a scanner finds no code at all.
 *
 * ⚠️ AND IT IS BLACK ON WHITE IN BOTH THEMES, WHICH IS THE ONE PLACE THIS
 * REPOSITORY'S COLOUR RULE DOES NOT REACH. A scanner needs the contrast the
 * format specifies; `bg-surface` in the dark theme is a dark square on a dark
 * square, i.e. an unreadable code that looks fine. The literals are the same two
 * `CertificateArtwork` writes, and for the same reason.
 */
export function SetupQr({ uri }: { uri: string }) {
  const modules = useMemo(() => {
    try {
      const code = qrcode(0, "M");

      code.addData(uri);
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
      // A URI too long for any version is not worth a blank square — the key
      // below it is still a complete way in.
      return null;
    }
  }, [uri]);

  if (modules === null) return null;

  const span = modules.count + 4;

  return (
    <svg
      viewBox={`-2 -2 ${span} ${span}`}
      className="mx-auto block h-48 w-48 rounded-xl border border-line"
      role="img"
      aria-label="رمز الإعداد للتحقّق بخطوتين"
      shapeRendering="crispEdges"
    >
      <rect x={-2} y={-2} width={span} height={span} fill="#ffffff" />
      {modules.cells.map(([row, column]) => (
        <rect
          key={`${row}-${column}`}
          x={column}
          y={row}
          width={1}
          height={1}
          fill="#000000"
        />
      ))}
    </svg>
  );
}
