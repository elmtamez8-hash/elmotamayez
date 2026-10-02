import { FALLBACK_FAMILY } from "@/lib/whiteboard/arabic-font";

/**
 * Encouragement stickers stamped on a page (US10; the owner chose that they are
 * SAVED with the page and exported, unlike the passing effects).
 *
 * ⚠️ NOTHING IS UPLOADED. A sticker's file id is a `template:` id — the server
 * accepts those without a lookup and never stores bytes for them (SceneValidator,
 * contracts/api.md) — and the picture is drawn here, from its name, on every
 * device that opens the board. The page stores the name; the drawing is code.
 */

export type StickerName = "excellent" | "well-done" | "bravo" | "great";

export const STICKERS: StickerName[] = ["excellent", "well-done", "bravo", "great"];

const LOOK: Record<StickerName, { text: string; fill: string; ring: string; glyph: string }> = {
  excellent: { text: "ممتاز", fill: "#f4b400", ring: "#b37400", glyph: "⭐" },
  "well-done": { text: "أحسنت", fill: "#2e9d4f", ring: "#1b6634", glyph: "👍" },
  bravo: { text: "برافو", fill: "#8e3fd1", ring: "#5b2394", glyph: "🏆" },
  great: { text: "رائع", fill: "#1677d2", ring: "#0d4f8f", glyph: "💯" },
};

const SIZE = 360;

/** The file id a sticker is stored under — matches the server's `template:` rule. */
export function stickerFileId(name: StickerName): string {
  return `template:sticker-${name}:v1`;
}

/** The sticker a file id names, or null for any other file. */
export function stickerOf(fileId: string): StickerName | null {
  const match = /^template:sticker-([a-z-]+):v1$/.exec(fileId);
  return match && (STICKERS as string[]).includes(match[1]) ? (match[1] as StickerName) : null;
}

export function stickerText(name: StickerName): string {
  return LOOK[name].text;
}

/** A rosette: a scalloped ring, a disc, the word, and a small emblem. PNG, 360 px. */
export async function renderSticker(name: StickerName): Promise<Blob> {
  const { text, fill, ring, glyph } = LOOK[name];
  const canvas = document.createElement("canvas");
  canvas.width = SIZE;
  canvas.height = SIZE;
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("no canvas");

  const c = SIZE / 2;
  // Scalloped outer ring.
  ctx.fillStyle = ring;
  ctx.beginPath();
  for (let i = 0; i <= 48; i++) {
    const a = (i / 48) * Math.PI * 2;
    const r = i % 2 === 0 ? c - 4 : c - 22;
    ctx.lineTo(c + Math.cos(a) * r, c + Math.sin(a) * r);
  }
  ctx.fill();
  // Disc with a white rim.
  ctx.fillStyle = "#ffffff";
  ctx.beginPath();
  ctx.arc(c, c, c - 34, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = fill;
  ctx.beginPath();
  ctx.arc(c, c, c - 46, 0, Math.PI * 2);
  ctx.fill();

  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillStyle = "#ffffff";
  // The board's own Arabic face (Cairo, registered under the fallback family).
  await document.fonts?.load(`700 96px "${FALLBACK_FAMILY}"`, text).catch(() => undefined);
  ctx.direction = "rtl";
  // Measured, then fitted: «ممتاز» at a fixed size ran into the rim (seen in the browser).
  const room = (c - 46) * 2 * 0.72;
  let size = 96;
  ctx.font = `700 ${size}px "${FALLBACK_FAMILY}", sans-serif`;
  const wide = ctx.measureText(text).width;
  if (wide > room) {
    size = Math.floor((size * room) / wide);
    ctx.font = `700 ${size}px "${FALLBACK_FAMILY}", sans-serif`;
  }
  ctx.fillText(text, c, c + 26);
  ctx.font = `64px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif`;
  ctx.fillText(glyph, c, c - 72);

  return new Promise<Blob>((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("no blob"))), "image/png"));
}
