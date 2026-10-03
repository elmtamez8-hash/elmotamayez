import {
  CaptureUpdateAction,
  convertToExcalidrawElements,
  exportToBlob,
  exportToCanvas,
  exportToSvg,
  hashElementsVersion,
  newElementWith,
  restoreElements,
  setCustomTextMetricsProvider,
  useHandleLibrary,
} from "@excalidraw/excalidraw";
import type { ExcalidrawElement, ExcalidrawFrameElement } from "@excalidraw/excalidraw/element/types";
import type { AppState, BinaryFileData, DataURL, ExcalidrawImperativeAPI, NormalizedZoomValue } from "@excalidraw/excalidraw/types";

import { injectFontsIntoSvg } from "@/lib/whiteboard/arabic-font";
import { paintTemplate, templateFileId, type TemplateName } from "@/lib/whiteboard/templates";
import type { Pen } from "@/lib/whiteboard/pens";
import { recognise } from "@/lib/whiteboard/magic-pen";
import type { WbCustomData } from "@/lib/whiteboard/custom-data";
import {
  BACKGROUNDS,
  PAGE_HEIGHT,
  PAGE_WIDTH,
  STREAM_DEFAULTS,
  fitViewport,
  migrateTemplatePictures,
  templateOnFrame,
  recolorForBackground,
  screenAt,
  screensIn,
  type BoardBackground,
} from "@/lib/whiteboard/page-model";

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
  return migrateTemplatePictures(restoreElements(elements as ExcalidrawElement[], null, { refreshDimensions: true, repairBindings: true }));
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

/** What a canvas mounted afresh keeps from the one it replaces: the theme, the tool and the pen. */
export function carriedState(api: BoardApi): Partial<AppState> {
  const state = api.getAppState();
  return Object.fromEntries(
    Object.entries(state).filter(([name]) => name === "theme" || name === "activeTool" || name.startsWith("currentItem")),
  ) as Partial<AppState>;
}
export type CarriedState = Partial<AppState>;

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
    // Transparent: the board's colour and template are ONE layer behind the
    // canvas (`PageCover`), drawn once, not a picture per screen in the scene.
    viewBackgroundColor: "transparent",
    currentItemStrokeColor: BACKGROUNDS[background].pen,
    currentItemStrokeWidth: STREAM_DEFAULTS.strokeWidth,
    currentItemFontSize: STREAM_DEFAULTS.fontSize,
    currentItemTextAlign: "right" as const,
    frameRendering: { enabled: true, name: false, outline: false, clip: true },
  };
}

/**
 * Fill the canvas with one 16:9 screen of the page — the first by default
 * (`scrollToContent` caps the zoom at 1 and floors it).
 */
export function fitToFrame(api: BoardApi, width: number, height: number, screen = 0): void {
  const view = fitViewport(width, height, screen);
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
    appState: { currentItemStrokeColor: BACKGROUNDS[to].pen },
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
export type ExportKind = "png" | "jpg" | "svg";

/**
 * Every picture a page needs drawn on its own: its pictures and its template's
 * (the template goes in AS pictures, `withTemplateImages`).
 */
export function pagePictureIds(elements: readonly BoardElement[]): string[] {
  return pictureIds(withTemplateImages(elements.filter((element) => !element.isDeleted)));
}

/**
 * One page as a JPEG for the board's PDF (story 5), its pictures passed in —
 * the canvas is not touched, so any page is drawn, not only the one shown.
 * JPEG at 1920 wide: a 100-page board stays a file a phone opens.
 */
export function pageImage(elements: readonly BoardElement[], pictures: PictureData[], background: BoardBackground): Promise<Blob> {
  const live = withTemplateImages(elements.filter((element) => !element.isDeleted));
  return exportToBlob({
    elements: live,
    files: Object.fromEntries(binaryFiles(pictures).map((file) => [file.id, file])),
    appState: { exportBackground: true, viewBackgroundColor: BACKGROUNDS[background].canvas },
    exportingFrame: pageFrame(live),
    mimeType: "image/jpeg",
    quality: 0.85,
  });
}

export async function exportPage(api: BoardApi, kind: ExportKind, background: BoardBackground): Promise<Blob> {
  // The template's picture must already be in the canvas's files (the caller adds it).
  const elements = withTemplateImages(api.getSceneElements());
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

  // JPG: what a phone's gallery and WhatsApp take without a question, a fraction of the PNG's size.
  if (kind === "jpg") {
    return exportToBlob({ elements, files, appState, exportingFrame, mimeType: "image/jpeg", quality: 0.9 });
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
 * in memory — no request, no stored thumbnail (R-07). Its pictures are passed IN, never added to the
 * canvas: scrolling the pages of a 100-page PDF used to load all 100 into it.
 */
export async function pageThumbnail(elements: readonly BoardElement[], pictures: PictureData[], background: BoardBackground): Promise<string> {
  const live = elements.filter((element) => !element.isDeleted);
  const drawing = await exportToCanvas({
    elements: live,
    files: Object.fromEntries(binaryFiles(pictures).map((file) => [file.id, file])),
    appState: { exportBackground: false, viewBackgroundColor: "transparent" },
    exportingFrame: pageFrame(live),
    maxWidthOrHeight: 320,
  });

  // The colour and the template are painted here as lines (vector, at 320 px):
  // no page-sized template picture decoded for every small picture.
  const canvas = document.createElement("canvas");
  canvas.width = drawing.width;
  canvas.height = drawing.height;
  const ctx = canvas.getContext("2d");
  if (!ctx) return drawing.toDataURL("image/png");
  ctx.fillStyle = BACKGROUNDS[background].canvas;
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  const name = frameTemplate(live);
  if (name) {
    const scale = canvas.width / PAGE_WIDTH;
    for (let screen = 0; screen * PAGE_HEIGHT * scale < canvas.height; screen++) {
      ctx.save();
      ctx.scale(scale, scale);
      ctx.translate(0, screen * PAGE_HEIGHT);
      paintTemplate(ctx, name);
      ctx.restore();
    }
  }
  ctx.drawImage(drawing, 0, 0);
  return canvas.toDataURL("image/png");
}

/** The built-in laser pointer (US8): Excalidraw's own tool, also on its «K» key. */
export function startLaser(api: BoardApi): void {
  api.setActiveTool({ type: "laser" });
}

/** Hand pictures to the canvas by OUR file id — a page names them, the bytes stay out of the scene. */
type PictureData = { id: string; dataURL: string; mimeType: "image/png" | "image/jpeg" };

function binaryFiles(pictures: PictureData[]): BinaryFileData[] {
  const now = Date.now();
  return pictures.map(
    (picture): BinaryFileData => ({
      id: picture.id as BinaryFileData["id"],
      dataURL: picture.dataURL as DataURL,
      mimeType: picture.mimeType,
      created: now,
    }),
  );
}

export function addPictures(api: BoardApi, pictures: PictureData[]): void {
  if (pictures.length === 0) return;
  api.addFiles(binaryFiles(pictures));
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

/**
 * Stamp a sticker on the page shown (US10): an image element inside the page's
 * frame, near its centre, as ONE undoable step. Its file is a `template:` id the
 * caller has already handed to the canvas (`addPictures`).
 */
export function placeSticker(api: BoardApi, fileId: string, name: string, offset: number): void {
  const elements = api.getSceneElementsIncludingDeleted();
  const frame = pageFrame(elements);
  const size = 220;
  const x = (frame?.x ?? 0) + (frame?.width ?? PAGE_WIDTH) / 2 - size / 2 + offset;
  const y = (frame?.y ?? 0) + (frame?.height ?? PAGE_HEIGHT) / 2 - size / 2 + offset;

  const [sticker] = convertToExcalidrawElements([
    {
      type: "image",
      fileId: fileId as BinaryFileData["id"],
      x,
      y,
      width: size,
      height: size,
      status: "saved",
      frameId: frame?.id ?? null,
      customData: { kind: "sticker", v: 1, name },
    },
  ]);

  api.updateScene({ elements: [...elements, sticker], captureUpdate: CaptureUpdateAction.IMMEDIATELY });
}

/** A rich object's source (story 6): a table now, an equation next. */
export type RichData = Extract<WbCustomData, { kind: "table" | "math" }>;

/**
 * Put a rich object on the page shown: an image element, its source in
 * `customData`, near the page's centre, as ONE undoable step. Its file was
 * uploaded first and already handed to the canvas.
 */
export function placeRichObject(api: BoardApi, fileId: string, pictureWidth: number, pictureHeight: number, data: RichData): void {
  const elements = api.getSceneElementsIncludingDeleted();
  const frame = pageFrame(elements);
  const { scrollX, scrollY, zoom, width: viewWidth, height: viewHeight } = api.getAppState();
  // The page clips what is outside it: a wide table is shrunk to fit, never cut.
  const room = { x: frame?.x ?? 0, y: frame?.y ?? 0, w: (frame?.width ?? PAGE_WIDTH) - 80, h: (frame?.height ?? PAGE_HEIGHT) - 80 };
  const fit = Math.min(1, room.w / pictureWidth, room.h / pictureHeight);
  const width = Math.round(pictureWidth * fit);
  const height = Math.round(pictureHeight * fit);
  // The middle of what the teacher is looking at, kept wholly inside the page.
  const centreX = viewWidth / 2 / zoom.value - scrollX;
  const centreY = viewHeight / 2 / zoom.value - scrollY;
  const clamp = (value: number, low: number, high: number) => Math.min(Math.max(value, low), Math.max(low, high));
  const x = clamp(centreX - width / 2, room.x + 40, room.x + 40 + room.w - width);
  const y = clamp(centreY - height / 2, room.y + 40, room.y + 40 + room.h - height);

  const [element] = convertToExcalidrawElements([
    {
      type: "image",
      fileId: fileId as BinaryFileData["id"],
      x,
      y,
      width,
      height,
      status: "saved",
      frameId: frame?.id ?? null,
      customData: data,
    },
  ]);
  // Selected at once, so «تعديل» is right there.
  api.updateScene({
    elements: [...elements, element],
    appState: { selectedElementIds: { [element.id]: true } },
    captureUpdate: CaptureUpdateAction.IMMEDIATELY,
  });
}

/**
 * «تعديل» saved: the SAME element (id, place, angle, layer) shows the new
 * picture. Its width stays what the teacher sized it to; its height follows the
 * new picture's proportions.
 */
export function replaceRichObject(api: BoardApi, elementId: string, fileId: string, width: number, height: number, data: RichData): boolean {
  const elements = api.getSceneElementsIncludingDeleted();
  // Gone meanwhile (deleted, the page reloaded): the caller says so.
  if (!elements.some((element) => element.id === elementId && !element.isDeleted)) return false;
  api.updateScene({
    elements: elements.map((element) =>
      element.id === elementId && element.type === "image"
        ? ({
            ...element,
            fileId: fileId as BinaryFileData["id"],
            height: Math.round((element.width * height) / Math.max(1, width)),
            customData: data,
            // A crop of the OLD picture would cut the new one wrongly.
            crop: null,
            version: element.version + 1,
            versionNonce: Math.floor(Math.random() * 2 ** 31),
            updated: Date.now(),
          } as BoardElement)
        : element,
    ),
    captureUpdate: CaptureUpdateAction.IMMEDIATELY,
  });
  return true;
}

/** The one rich object selected, if exactly one element is and it is one. */
export function selectedRichObject(api: BoardApi): { id: string; data: RichData } | null {
  const ids = Object.keys(api.getAppState().selectedElementIds);
  if (ids.length !== 1) return null;
  const element = api.getSceneElements().find((e) => e.id === ids[0]);
  const data = element?.customData as RichData | undefined;
  return element && (data?.kind === "table" || data?.kind === "math") ? { id: element.id, data } : null;
}

/** A ready pen (US9): the freehand tool with that pen's width, opacity and colour. */
export function applyPen(api: BoardApi, pen: Pen): void {
  const colour = pen.colour ?? api.getAppState().currentItemStrokeColor;
  api.updateScene({
    appState: {
      currentItemStrokeWidth: pen.strokeWidth,
      currentItemOpacity: pen.opacity,
      currentItemStrokeColor: colour,
    },
    captureUpdate: CaptureUpdateAction.NEVER,
  });
  api.setActiveTool({ type: "freedraw" });
}

/**
 * «القلم السحري»: the freehand stroke `elementId`, recognised, becomes Excalidraw's
 * own clean shape in the same colour, width and frame — one undoable step, so
 * Ctrl+Z gives the hand-drawn stroke back. A stroke it is unsure of is left alone.
 * Returns whether it swapped.
 */
function boxOf(points: [number, number][]): { width: number; height: number } {
  const xs = points.map((p) => p[0]);
  const ys = points.map((p) => p[1]);
  return { width: Math.max(...xs) - Math.min(...xs), height: Math.max(...ys) - Math.min(...ys) };
}

export function magicStroke(api: BoardApi, elementId: string): boolean {
  const elements = api.getSceneElementsIncludingDeleted();
  const stroke = elements.find((e) => e.id === elementId && !e.isDeleted);
  if (!stroke || stroke.type !== "freedraw") return false;
  // ~24 screen pixels, whatever the zoom: below that a stroke is handwriting.
  const shape = recognise(
    stroke.points.map(([px, py]) => [stroke.x + px, stroke.y + py] as [number, number]),
    24 / api.getAppState().zoom.value,
  );
  if (!shape) return false;

  const look = {
    strokeColor: stroke.strokeColor,
    strokeWidth: Math.max(STREAM_DEFAULTS.minStrokeWidth, stroke.strokeWidth),
    opacity: stroke.opacity,
    roughness: 0,
    frameId: stroke.frameId,
  };
  const relative = (points: [number, number][]) => {
    const [ox, oy] = points[0];
    return { x: ox, y: oy, points: points.map(([px, py]) => [px - ox, py - oy] as [number, number]) };
  };
  const skeleton =
    shape.type === "line" || shape.type === "arrow"
      ? { type: shape.type, ...relative([shape.from, shape.to]), ...look }
      : shape.type === "triangle"
        ? { type: "line" as const, ...relative([...shape.points, shape.points[0]]), ...look }
        : { type: shape.type, x: shape.x, y: shape.y, width: shape.width, height: shape.height, ...look };
  const [converted] = convertToExcalidrawElements([skeleton]);
  // A `line` skeleton keeps a 100×0 box whatever its points (only arrows are
  // measured), so a line or a triangle is given the box its points make.
  const clean = "points" in skeleton ? newElementWith(converted, boxOf(skeleton.points)) : converted;

  api.updateScene({
    elements: [...elements.map((e) => (e.id === elementId ? newElementWith(e, { isDeleted: true }) : e)), clean],
    captureUpdate: CaptureUpdateAction.IMMEDIATELY,
  });
  return true;
}

/** The template a page uses, read from its frame (`customData.template`). */
export function frameTemplate(elements: readonly BoardElement[]): TemplateName | null {
  return templateOnFrame(elements);
}

/**
 * Put a background template on the page shown (US9, FR-030), or take it off
 * (`null`). The template is a NAME on the page's frame, drawn once behind the
 * whole canvas (`PageCover`) — not pictures in the scene: one picture per
 * screen kept a page-sized bitmap per screen in memory and half-undid when the
 * page had grown. One undoable step by default; a new page inheriting the
 * template takes it with `undoable = false`.
 */
export function setPageTemplate(
  api: BoardApi,
  name: TemplateName | null,
  undoable = true,
): void {
  const elements = api.getSceneElementsIncludingDeleted();
  const frame = pageFrame(elements);
  if (!frame || frameTemplate(elements) === name) return;
  const customData = { ...(frame.customData ?? { kind: "frame", v: 1 }) } as Record<string, unknown>;
  if (name === null) delete customData.template;
  else customData.template = name;
  // A new version AND nonce, or autosave's hash never sees the change.
  const next = { ...frame, customData, version: frame.version + 1, versionNonce: Math.floor(Math.random() * 2 ** 31) } as BoardElement;
  api.updateScene({ elements: elements.map((e) => (e.id === frame.id ? next : e)), captureUpdate: undoable ? CaptureUpdateAction.IMMEDIATELY : CaptureUpdateAction.NEVER });
}

/**
 * One screen's copy of a template, as an image element — for EXPORTS only (a
 * file is the page alone, without the live layer behind the canvas).
 */
function templateImage(frame: ExcalidrawFrameElement, name: TemplateName, screen: number): BoardElement {
  const [image] = convertToExcalidrawElements([
    {
      type: "image",
      fileId: templateFileId(name) as BinaryFileData["id"],
      x: frame.x,
      y: frame.y + screen * PAGE_HEIGHT,
      width: frame.width,
      height: PAGE_HEIGHT,
      status: "saved",
      locked: true,
      frameId: frame.id,
    },
  ]);
  return image;
}

/** The page with its template as pictures under everything, for an export. */
function withTemplateImages(elements: readonly BoardElement[]): readonly BoardElement[] {
  const frame = pageFrame(elements);
  const name = frameTemplate(elements);
  if (!frame || !name) return elements;
  return [...Array.from({ length: screensIn(frame.height) }, (_, screen) => templateImage(frame, name, screen)), ...elements];
}


/** How many screens the page shown holds. */
export function pageScreens(api: BoardApi): number {
  return screensIn(pageFrame(api.getSceneElements())?.height ?? PAGE_HEIGHT);
}

/** The screen the teacher is on now. */
export function currentScreen(api: BoardApi, viewportHeight: number): number {
  const state = api.getAppState();
  return screenAt(state.scrollY, state.zoom.value, viewportHeight, pageScreens(api));
}

/**
 * Show screen `index` of the page — growing the page downward first when it has
 * fewer screens (owner, 2026-10-02: «move down to keep explaining in empty
 * space»). The frame gets taller; the template behind the canvas repeats on
 * its own. Growing is not an undo step: undo is for what the teacher drew.
 * Answers the screen shown.
 */
export function showScreen(api: BoardApi, width: number, height: number, index: number): number {
  const target = Math.max(0, index);
  const elements = api.getSceneElementsIncludingDeleted();
  const frame = pageFrame(elements);
  const screens = screensIn(frame?.height ?? PAGE_HEIGHT);

  if (frame && target >= screens) {
    const grown = { ...frame, height: (target + 1) * PAGE_HEIGHT, version: frame.version + 1, versionNonce: Math.floor(Math.random() * 2 ** 31) } as BoardElement;
    api.updateScene({
      elements: elements.map((e) => (e.id === frame.id ? grown : e)),
      captureUpdate: CaptureUpdateAction.NEVER,
    });
  }

  fitToFrame(api, width, height, target);
  return target;
}

/** The template the page shown uses, if any. */
export function pageTemplate(api: BoardApi): TemplateName | null {
  return frameTemplate(api.getSceneElements());
}

/**
 * A line drawn along a geometry instrument (US9, FR-029): an ordinary line on the
 * page shown — a straight segment, or an arc as many points — in the pen's
 * current colour and width, with no hand-drawn wobble. One undoable step.
 * The instrument itself is never saved.
 */
export function addStroke(api: BoardApi, points: [number, number][]): void {
  if (points.length < 2) return;
  const elements = api.getSceneElementsIncludingDeleted();
  const frame = pageFrame(elements);
  const state = api.getAppState();
  const [x, y] = points[0];

  const relative = points.map(([px, py]) => [px - x, py - y] as [number, number]);
  const [converted] = convertToExcalidrawElements([
    {
      type: "line",
      x,
      y,
      points: relative,
      strokeColor: state.currentItemStrokeColor,
      strokeWidth: Math.max(STREAM_DEFAULTS.minStrokeWidth, state.currentItemStrokeWidth),
      roughness: 0,
      frameId: frame?.id ?? null,
    },
  ]);
  // A `line` skeleton keeps a 100×0 box whatever its points; it is given its own.
  const line = newElementWith(converted, boxOf(relative));

  api.updateScene({ elements: [...elements, line], captureUpdate: CaptureUpdateAction.IMMEDIATELY });
}

const LIBRARY_KEY = "whiteboard.library";

/**
 * The teacher's element library, kept in THIS browser (owner, 2026-10-02):
 * what Excalidraw's library sidebar holds, and what «تصفّح المكتبات» adds.
 * `save` may throw (storage full or blocked) — Excalidraw then says so.
 */
const libraryAdapter = {
  load: () => {
    try {
      const raw = localStorage.getItem(LIBRARY_KEY);
      return raw ? { libraryItems: portableItems(JSON.parse(raw)) } : null;
    } catch {
      return null;
    }
  },
  save: ({ libraryItems }: { libraryItems: unknown }) => localStorage.setItem(LIBRARY_KEY, JSON.stringify(portableItems(libraryItems))),
};

/**
 * Only items that work on ANY board are kept: an uploaded picture belongs to the
 * board it was uploaded to, and the server refuses it on another (`unknown_file`)
 * — every save of that page would fail. A sticker or template (`template:` id) is
 * drawn from its name everywhere, so it stays.
 */
function portableItems<T>(items: T): T {
  if (!Array.isArray(items)) return [] as T;
  return items.filter((item: { elements?: { type?: string; fileId?: string | null }[] }) =>
    (item?.elements ?? []).every((e) => e.type !== "image" || (e.fileId ?? "").startsWith("template:")),
  ) as T;
}

/**
 * Takes a library sent back from libraries.excalidraw.com (`#addLibrary=…`)
 * and keeps the library between visits. Without it the «add» on that site
 * reached the board and nothing read it.
 */
export function useBoardLibrary(api: BoardApi | null): void {
  useHandleLibrary({ excalidrawAPI: api, adapter: libraryAdapter });
}

/**
 * A new page's elements with an imported picture on it (story 4 — a picture,
 * or one page of a PDF read in the browser): 1920 wide, locked UNDER everything
 * drawn, and the frame as many screens tall as the picture needs. Pure, so a
 * PDF's pages are built without being shown one by one.
 */
export function withPagePicture(elements: readonly BoardElement[], fileId: string, width: number, height: number): BoardElement[] {
  const frame = pageFrame(elements);
  if (!frame) return [...elements];
  const scaled = Math.round((height * PAGE_WIDTH) / Math.max(1, width));
  const screens = Math.max(1, Math.ceil(scaled / PAGE_HEIGHT));
  const [picture] = convertToExcalidrawElements([
    {
      type: "image",
      fileId: fileId as BinaryFileData["id"],
      x: frame.x,
      y: frame.y,
      width: PAGE_WIDTH,
      height: scaled,
      status: "saved",
      locked: true,
      frameId: frame.id,
      customData: { kind: "doc-background", v: 1, importUuid: "picture", page: 1 },
    },
  ]);
  const grown =
    screens * PAGE_HEIGHT > frame.height
      ? ({ ...frame, height: screens * PAGE_HEIGHT, version: frame.version + 1, versionNonce: Math.floor(Math.random() * 2 ** 31) } as BoardElement)
      : frame;
  return [picture as BoardElement, ...elements.map((e) => (e.id === frame.id ? grown : e))];
}
