import {
  CaptureUpdateAction,
  exportToBlob,
  exportToCanvas,
  exportToSvg,
  hashElementsVersion,
  restoreElements,
  setCustomTextMetricsProvider,
} from "@excalidraw/excalidraw";
import type { ExcalidrawElement, ExcalidrawFrameElement } from "@excalidraw/excalidraw/element/types";
import type { BinaryFileData, DataURL, ExcalidrawImperativeAPI, NormalizedZoomValue } from "@excalidraw/excalidraw/types";

import { injectFontsIntoSvg } from "@/lib/whiteboard/arabic-font";
import { BACKGROUNDS, STREAM_DEFAULTS, fitViewport, recolorForBackground, type BoardBackground } from "@/lib/whiteboard/page-model";

/**
 * THE door to Excalidraw (spec 039, R-02). Every call into the library goes through
 * this file — `BoardCanvas.tsx` alone imports the `<Excalidraw>` component and its
 * CSS — so an upgrade past 0.18 (whose `master` already renames `scrollToContent`
 * and reshapes `setActiveTool`) touches one place. A vitest guard
 * (`single-excalidraw-door.test.ts`) refuses any other importer.
 */

export type BoardApi = ExcalidrawImperativeAPI;
export type BoardElement = ExcalidrawElement;

let metricsInstalled = false;

/**
 * Excalidraw sizes a text box by the ADVANCE width and paints the element on a
 * canvas exactly that wide; an Arabic final letter's ink reaches past its advance,
 * so «درجة» painted as «درجا» in the phase 0 lab. max(advance, ink box) fixes it
 * through the hook the library exposes for this. Install before any text exists.
 */
export function installTextMetrics(): void {
  if (metricsInstalled || typeof document === "undefined") return;
  metricsInstalled = true;

  const canvas = document.createElement("canvas");
  setCustomTextMetricsProvider({
    getLineWidth(text, fontString) {
      const context = canvas.getContext("2d");
      if (!context) return 0;
      context.font = fontString;
      const m = context.measureText(text);
      return Math.max(m.width, Math.abs(m.actualBoundingBoxLeft) + Math.abs(m.actualBoundingBoxRight));
    },
  });
}

/**
 * Elements as stored on the server, made whole by Excalidraw's own restore — and
 * re-measured, because a text element measured before the Arabic face loaded keeps
 * a box too narrow for its glyphs (the lab's clipped «درجة»).
 */
export function restorePage(elements: readonly unknown[]): BoardElement[] {
  return restoreElements(elements as ExcalidrawElement[], null, { refreshDimensions: true, repairBindings: true });
}

/**
 * Show another page. History is ONE per Excalidraw instance, so it is cleared on
 * every switch — without it, undo on the new page replays the old page's edits.
 * An open text editor is committed first, or its text lands on the wrong page.
 */
export function loadPage(api: BoardApi, elements: readonly BoardElement[]): void {
  (document.activeElement as HTMLElement | null)?.blur?.();
  api.setActiveTool({ type: "selection" });
  api.updateScene({ elements, captureUpdate: CaptureUpdateAction.NEVER });
  api.history.clear();
}

/**
 * The board's first app state: already fitted to the window (so the first paint
 * is the page, not a jump), the streaming defaults, right-aligned text for Arabic,
 * and the frame drawn without its name or outline — the student sees the page,
 * not Excalidraw's chrome around it.
 */
export function initialAppState(background: BoardBackground, width: number, height: number) {
  const view = fitViewport(width, height);

  return {
    zoom: { value: view.zoom as NormalizedZoomValue },
    scrollX: view.scrollX,
    scrollY: view.scrollY,
    viewBackgroundColor: BACKGROUNDS[background].canvas,
    currentItemStrokeColor: BACKGROUNDS[background].pen,
    currentItemStrokeWidth: STREAM_DEFAULTS.strokeWidth,
    currentItemFontSize: STREAM_DEFAULTS.fontSize,
    currentItemTextAlign: "right" as const,
    frameRendering: { enabled: true, name: false, outline: false, clip: true },
  };
}

/** Fill the canvas with the 16:9 frame (`scrollToContent` caps the zoom at 1 and floors it). */
export function fitToFrame(api: BoardApi, width: number, height: number): void {
  const view = fitViewport(width, height);
  api.updateScene({
    appState: { zoom: { value: view.zoom as NormalizedZoomValue }, scrollX: view.scrollX, scrollY: view.scrollY },
    captureUpdate: CaptureUpdateAction.NEVER,
  });
}

/** The canvas colour and default pen for a background, and every drawn colour remapped (owner decision). */
export function applyBackground(api: BoardApi, from: BoardBackground, to: BoardBackground): void {
  const recoloured = recolorForBackground(api.getSceneElements(), from, to);
  const byId = new Map(recoloured.map((element) => [element.id, element]));

  api.updateScene({
    elements: api.getSceneElementsIncludingDeleted().map((element) => byId.get(element.id) ?? element),
    appState: { viewBackgroundColor: BACKGROUNDS[to].canvas, currentItemStrokeColor: BACKGROUNDS[to].pen },
    captureUpdate: recoloured.length > 0 ? CaptureUpdateAction.IMMEDIATELY : CaptureUpdateAction.NEVER,
  });
}

function pageFrame(elements: readonly BoardElement[]): ExcalidrawFrameElement | null {
  return (elements.find((element) => element.type === "frame" && !element.isDeleted) as ExcalidrawFrameElement | undefined) ?? null;
}

/**
 * The current page as a file. PNG at 1.5× (`exportScale` is ignored without
 * `maxWidthOrHeight` in 0.18.1, so the scale goes through `getDimensions`); SVG
 * with fonts inlined by us — Excalidraw's own inlining runs WebAssembly the
 * production CSP does not allow, and its failure path points at esm.sh.
 */
export async function exportPage(api: BoardApi, kind: "png" | "svg", background: BoardBackground): Promise<Blob> {
  const elements = api.getSceneElements();
  const appState = { exportBackground: true, viewBackgroundColor: BACKGROUNDS[background].canvas };
  const files = api.getFiles();
  const exportingFrame = pageFrame(elements);

  if (kind === "png") {
    return exportToBlob({
      elements,
      files,
      appState,
      exportingFrame,
      mimeType: "image/png",
      getDimensions: (width: number, height: number) => ({ width: width * 1.5, height: height * 1.5, scale: 1.5 }),
    });
  }

  const svg = await exportToSvg({ elements, files, appState, exportingFrame, skipInliningFonts: true });
  await injectFontsIntoSvg(svg);

  return new Blob([svg.outerHTML], { type: "image/svg+xml" });
}

/**
 * A number that changes when any element changes — the autosave's «is this page
 * dirty?». `hashElementsVersion`, not `getSceneVersion`: 0.18.1 marks the latter
 * «@deprecated unsafe» (a sum of versions, so two edits can cancel out).
 */
export function sceneVersion(elements: readonly BoardElement[]): number {
  return hashElementsVersion(elements);
}

/**
 * The page document exactly as the server stores it (data-model.md): live
 * elements only — deleted ones are dropped, as Excalidraw's own export drops them
 * — the files they reference by id, and the one app-state field a page carries.
 */
export function pageDocument(elements: readonly BoardElement[], background: BoardBackground): string {
  const live = elements.filter((element) => !element.isDeleted);
  const fileIds = [...new Set(live.flatMap((element) => (element.type === "image" && element.fileId ? [element.fileId] : [])))];

  return JSON.stringify({ v: 1, elements: live, appState: { viewBackgroundColor: BACKGROUNDS[background].canvas }, fileIds });
}

/**
 * A page's small picture for the pages strip (US3): ≤ 320 px, from the elements
 * in memory — no request, no stored thumbnail (R-07).
 */
export async function pageThumbnail(api: BoardApi, elements: readonly BoardElement[], background: BoardBackground): Promise<string> {
  const live = elements.filter((element) => !element.isDeleted);
  const canvas = await exportToCanvas({
    elements: live,
    files: api.getFiles(),
    appState: { exportBackground: true, viewBackgroundColor: BACKGROUNDS[background].canvas },
    exportingFrame: pageFrame(live),
    maxWidthOrHeight: 320,
  });

  return canvas.toDataURL("image/png");
}

/** Hand pictures to the canvas by OUR file id — a page names them, the bytes stay out of the scene. */
export function addPictures(api: BoardApi, pictures: { id: string; dataURL: string; mimeType: "image/png" | "image/jpeg" }[]): void {
  if (pictures.length === 0) return;
  const now = Date.now();
  api.addFiles(
    pictures.map(
      (picture): BinaryFileData => ({
        id: picture.id as BinaryFileData["id"],
        dataURL: picture.dataURL as DataURL,
        mimeType: picture.mimeType,
        created: now,
      }),
    ),
  );
}

/** The ids of the pictures a page shows. */
export function pictureIds(elements: readonly BoardElement[]): string[] {
  return elements.flatMap((element) => (element.type === "image" && !element.isDeleted && element.fileId ? [element.fileId] : []));
}

/**
 * Move a page's elements onto another page: the frame takes the new page's id
 * and every element inside it follows (`frameId`). Used to keep the teacher's
 * copy as a new page after a conflict.
 */
export function reframe(elements: readonly BoardElement[], pageUuid: string): BoardElement[] {
  const from = pageFrame(elements)?.id;
  const to = `frame:${pageUuid}`;

  return elements.map((element) => {
    if (element.id === from) return { ...element, id: to } as BoardElement;
    if (from && element.frameId === from) return { ...element, frameId: to } as BoardElement;
    return element;
  });
}
