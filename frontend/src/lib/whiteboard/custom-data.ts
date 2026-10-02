/**
 * The whiteboard's own data on Excalidraw elements (`element.customData`), each
 * shape versioned so a later release can migrate an older board (FR-028).
 *
 * ⚠️ AN UNKNOWN KIND IS KEPT, NEVER DROPPED. A newer release (or, later, another
 * participant in a collaboration session) may write a kind this code does not
 * know; dropping it would delete that person's work the first time an older tab
 * saved the page. It stays exactly as it arrived — an ordinary image on screen.
 */

export type TemplateName = "lined" | "grid" | "dotted" | "isometric" | "graph" | "arabic-lines";

export type WbCustomData =
  | { kind: "frame"; v: 1 }
  | { kind: "doc-background"; v: 1; importUuid: string; page: number }
  | { kind: "template"; v: 1; name: TemplateName }
  | {
      kind: "table";
      v: 1;
      dir: "rtl" | "ltr";
      rows: { cells: { text: string; fill?: string }[] }[];
      colWidths: number[];
    }
  | { kind: "math"; v: 1; latex: string; display: boolean }
  | { kind: "quran"; v: 1; surah: number; from: number; to: number; edition: "tanzil-uthmani-1.1" }
  | { kind: "sticker"; v: 1; name: string };

export type WbKind = WbCustomData["kind"];

const CURRENT: Record<WbKind, number> = {
  frame: 1,
  "doc-background": 1,
  template: 1,
  sticker: 1,
  table: 1,
  math: 1,
  quran: 1,
};

/**
 * One step per (kind, version) — the place a future v2 writes its upgrade.
 * Empty today: every kind is still at v1.
 */
const UPGRADES: Partial<Record<WbKind, Record<number, (data: Record<string, unknown>) => Record<string, unknown>>>> = {};

function isKnown(kind: unknown): kind is WbKind {
  return typeof kind === "string" && Object.prototype.hasOwnProperty.call(CURRENT, kind);
}

/**
 * Raise any known kind to its current version, step by step. An unknown kind, or a
 * value that is not our data at all, is returned unchanged.
 */
export function migrateCustomData(data: unknown): unknown {
  if (typeof data !== "object" || data === null) return data;

  const record = data as Record<string, unknown>;
  if (!isKnown(record.kind)) return data;

  let current: Record<string, unknown> = { ...record };
  let version = typeof current.v === "number" ? current.v : 1;
  const target = CURRENT[record.kind];

  while (version < target) {
    const step = UPGRADES[record.kind]?.[version];
    if (!step) break;
    current = { ...step(current), v: version + 1 };
    version += 1;
  }

  return current;
}
