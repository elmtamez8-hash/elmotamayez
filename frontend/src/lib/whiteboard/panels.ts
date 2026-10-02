/**
 * The board's floating panels, each shown, folded away or hidden until the
 * pointer comes near (owner, 2026-10-02). A per-viewer convenience, so the
 * choices live in this browser only.
 *
 * Excalidraw's own panels are found by its class names, as «عرض» already does
 * (`.layer-ui__wrapper`); ours carry `data-panel`. A class renamed by an
 * Excalidraw upgrade leaves that panel always shown — never hidden for good.
 */

export type PanelId = "board" | "tools" | "pages" | "zoom" | "library";
export type PanelMode = "shown" | "folded" | "auto";

export const PANELS: { id: PanelId; selector: string }[] = [
  { id: "board", selector: '[data-panel="board"]' },
  { id: "tools", selector: ".shapes-section" },
  { id: "pages", selector: '[data-panel="pages"]' },
  { id: "zoom", selector: ".layer-ui__wrapper__footer-left" },
  { id: "library", selector: ".default-sidebar-trigger, .main-menu-trigger" },
];

export const PANEL_MODES: PanelMode[] = ["shown", "folded", "auto"];

/** How far (px) from a hidden panel the pointer brings it back. */
export const NEAR_PX = 56;

const KEY = "whiteboard.panels";

export function readPanelModes(): Partial<Record<PanelId, PanelMode>> {
  try {
    const stored = JSON.parse(localStorage.getItem(KEY) ?? "{}") as Record<string, unknown>;
    return Object.fromEntries(
      PANELS.flatMap(({ id }) => (PANEL_MODES.includes(stored[id] as PanelMode) ? [[id, stored[id] as PanelMode]] : [])),
    );
  } catch {
    return {};
  }
}

export function writePanelModes(modes: Partial<Record<PanelId, PanelMode>>): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(modes));
  } catch {
    // Private window or blocked storage: the choice lasts this visit only.
  }
}

/** Is the point within `reach` of the rectangle? */
export function isNear(rect: { left: number; top: number; right: number; bottom: number }, x: number, y: number, reach = NEAR_PX): boolean {
  return x >= rect.left - reach && x <= rect.right + reach && y >= rect.top - reach && y <= rect.bottom + reach;
}
