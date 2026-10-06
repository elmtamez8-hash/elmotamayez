"use client";

import "@excalidraw/excalidraw/index.css";

import { Excalidraw } from "@excalidraw/excalidraw";
import dynamic from "next/dynamic";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { ensureArabicFont } from "@/lib/whiteboard/arabic-font";
import { boards, parseScene, type BoardDetail, type BoardPagePayload } from "@/lib/whiteboard/api";
import {
  addPictures,
  addStroke,
  applyBackground,
  applyPen,
  magicStroke,
  currentScreen,
  exportPage,
  fitToFrame,
  carriedState,
  initialAppState,
  type CarriedState,
  installTextMetrics,
  loadPage,
  pageDocument,
  frameTemplate,
  pageScreens,
  pageTemplate,
  useBoardLibrary,
  pageImage,
  pagePictureIds,
  pageThumbnail,
  placeRichObject,
  replaceRichObject,
  selectedRichObject,
  type RichData,
  pictureIds,
  placeSticker,
  reframe,
  restorePage,
  withPagePicture,
  sceneVersion,
  setPageTemplate,
  showScreen,
  startLaser,
  type BoardApi,
  type BoardElement,
  type ExportKind,
} from "@/lib/whiteboard/excalidraw-api";
import { BACKGROUNDS, PAGE_HEIGHT, PAGE_WIDTH, type BoardBackground } from "@/lib/whiteboard/page-model";
import {
  playAlarm,
  playBubbles,
  playBuzzer,
  playChime,
  playCrash,
  playFanfare,
  playGavel,
  playRecording,
  playSplat,
  playTaps,
  playWhistle,
  playWhoosh,
} from "@/lib/whiteboard/effect-sounds";
import type { Celebration, Effect, Stunt as StuntKind, TrailStyle } from "@/lib/whiteboard/effects";
import type { InstrumentKind } from "@/lib/whiteboard/geometry";
import { PENS, type PenId } from "@/lib/whiteboard/pens";
import { renderSticker, stickerFileId, stickerOf, type StickerName } from "@/lib/whiteboard/stickers";
import { readPanelModes, writePanelModes, type PanelId, type PanelMode } from "@/lib/whiteboard/panels";
import { renderTemplate, templateFileId, templateOf, type TemplateName } from "@/lib/whiteboard/templates";
import { ImageRefused, uploadBoardImage } from "@/lib/whiteboard/image-insert";
import { boardPdf } from "@/lib/whiteboard/pdf-export";
import { pdfPages } from "@/lib/whiteboard/pdf-pages";
import { createPictureCache } from "@/lib/whiteboard/picture-cache";
import { WB } from "@/lib/whiteboard/strings";
import { Modal } from "@/components/ui/Modal";
import { BoardLoading } from "@/components/whiteboard/BoardLoading";
import { BoardToolbar } from "@/components/whiteboard/BoardToolbar";
import { ConflictDialog } from "@/components/whiteboard/ConflictDialog";
import { EffectsBar } from "@/components/whiteboard/EffectsBar";
import { PresenterBar } from "@/components/whiteboard/PresenterBar";
import { TeachingBar, type PassingTool } from "@/components/whiteboard/TeachingBar";
import { LockBanner } from "@/components/whiteboard/LockBanner";
import { PanelModesMenu, PanelVisibility } from "@/components/whiteboard/PanelVisibility";
import type { ImportView } from "@/components/whiteboard/ImportPanel";
import { AcademyLibrary } from "@/components/whiteboard/AcademyLibrary";
import { blankGraph, GraphError, renderGraph } from "@/lib/whiteboard/graph";
import { MathError, renderMath } from "@/lib/whiteboard/math";
import { blankTable, parseClipboardTable, renderTable } from "@/lib/whiteboard/table";
import { PageCover } from "@/components/whiteboard/PageCover";
import { PageStore } from "@/lib/whiteboard/page-store";
import { PagesSidebar, PagesTab } from "@/components/whiteboard/PagesSidebar";
import { AttentionBanner } from "@/components/whiteboard/overlays/AttentionBanner";
import type { View } from "@/components/whiteboard/overlays/GeometryTool";
import { SaveIndicator } from "@/components/whiteboard/SaveIndicator";
import { useBoardSession, type PageAccess } from "@/components/whiteboard/useBoardSession";

/*
 * Windows and classroom tools a lesson may never open load on their own, after
 * the board: the opening carries the canvas only. Warmed once the board is shown
 * (`warmWindows`), so the class never waits for one either.
 */
const ImportPanel = dynamic(() => import("@/components/whiteboard/ImportPanel").then((m) => m.ImportPanel));
const LessonExportPanel = dynamic(() => import("@/components/whiteboard/LessonExportPanel").then((m) => m.LessonExportPanel));
const GraphEditor = dynamic(() => import("@/components/whiteboard/rich/GraphEditor").then((m) => m.GraphEditor));
const MathEditor = dynamic(() => import("@/components/whiteboard/rich/MathEditor").then((m) => m.MathEditor));
const TableEditor = dynamic(() => import("@/components/whiteboard/rich/TableEditor").then((m) => m.TableEditor));
const BalloonPop = dynamic(() => import("@/components/whiteboard/overlays/BalloonPop").then((m) => m.BalloonPop));
const Stunt = dynamic(() => import("@/components/whiteboard/overlays/Stunt").then((m) => m.Stunt));
const Celebrate = dynamic(() => import("@/components/whiteboard/overlays/Celebrate").then((m) => m.Celebrate));
const PointerTrail = dynamic(() => import("@/components/whiteboard/overlays/PointerTrail").then((m) => m.PointerTrail));
const Curtain = dynamic(() => import("@/components/whiteboard/overlays/Curtain").then((m) => m.Curtain));
const GeometryTool = dynamic(() => import("@/components/whiteboard/overlays/GeometryTool").then((m) => m.GeometryTool));
const OverlayLayer = dynamic(() => import("@/components/whiteboard/overlays/GeometryTool").then((m) => m.OverlayLayer));
const Magnifier = dynamic(() => import("@/components/whiteboard/overlays/Magnifier").then((m) => m.Magnifier));
const Spotlight = dynamic(() => import("@/components/whiteboard/overlays/Spotlight").then((m) => m.Spotlight));
const Wheel = dynamic(() => import("@/components/whiteboard/overlays/Wheel").then((m) => m.Wheel));
const Timer = dynamic(() => import("@/components/whiteboard/overlays/Timer").then((m) => m.Timer));
const Calculator = dynamic(() => import("@/components/whiteboard/overlays/Calculator").then((m) => m.Calculator));

let warmed = false;
function warmWindows(): void {
  if (warmed) return;
  warmed = true;
  const warm = () => {
    void import("@/components/whiteboard/ImportPanel");
    void import("@/components/whiteboard/LessonExportPanel");
    void import("@/components/whiteboard/rich/GraphEditor");
    void import("@/components/whiteboard/rich/MathEditor");
    void import("@/components/whiteboard/rich/TableEditor");
    void import("@/components/whiteboard/overlays/BalloonPop");
    void import("@/components/whiteboard/overlays/Stunt");
    void import("@/components/whiteboard/overlays/Celebrate");
    void import("@/components/whiteboard/overlays/PointerTrail");
    void import("@/components/whiteboard/overlays/Curtain");
    void import("@/components/whiteboard/overlays/GeometryTool");
    void import("@/components/whiteboard/overlays/Magnifier");
    void import("@/components/whiteboard/overlays/Spotlight");
    void import("@/components/whiteboard/overlays/Wheel");
    void import("@/components/whiteboard/overlays/Timer");
    void import("@/components/whiteboard/overlays/Calculator");
  };
  if ("requestIdleCallback" in window) window.requestIdleCallback(warm, { timeout: 5000 });
  else setTimeout(warm, 2000);
}

/**
 * The teacher's board (spec 039 · US1): Excalidraw, one 16:9 page at a time.
 *
 * ⚠️ THIS FILE IMPORTS ONLY THE `<Excalidraw>` COMPONENT AND ITS CSS from the
 * library; every call goes through `lib/whiteboard/excalidraw-api.ts`.
 *
 * ⚠️ STUDENTS SEE THIS TAB LIVE, not an export: whatever lies outside the frame is
 * painted over (the mask), and «عرض» hides every tool until the pointer nears the
 * top edge (owner decision on phase 0 — Excalidraw's zen mode keeps its toolbar).
 *
 * Saving is story 2 (`useBoardSession`): one editor at a time, a draft on this
 * device, and the server 1.5 s after drawing stops.
 */

const REVEAL_TOP_PX = 48;
/** The aeroplane's late chime, cancelled when another stunt starts first. */
let landing = 0;
/** Each stunt's sound (synthesised: no file to fetch, nothing to license). */
const STUNT_SOUNDS: Record<StuntKind, () => void> = {
  airplane: () => {
    playWhoosh();
    landing = window.setTimeout(playChime, 1700); // the gift lands
  },
  egg: playSplat,
  tomato: playSplat,
  brick: playCrash,
  whistle: () => playWhistle(),
  stick: playTaps,
  warning: playAlarm,
  wrong: playBuzzer,
  yellowCard: () => playWhistle(true),
  redCard: () => playWhistle(true),
};
/**
 * The canvas is transparent over the board's own colour layer (PageCover):
 * Excalidraw's own background picker would paint over it. A constant, so the
 * prop is the same object every render.
 */
const UI_OPTIONS = { canvasActions: { changeViewBackgroundColor: false } };
/** Pictures handed to the canvas: the page shown and this many either side. */
const PICTURE_REACH = 2;
/**
 * Excalidraw frees a picture only when it unmounts (no public API removes
 * one), so a lesson that walks a 100-page PDF ended holding every page's
 * picture, decoded. Past this many the canvas is mounted afresh on the next
 * page change, keeping the page, the theme and the pen.
 * ponytail: a count, not measured memory; lower it if long boards are still heavy.
 */
const MAX_HELD_PICTURES = 24;
const SOUND_KEY = "whiteboard.effects.sound";

/** A per-viewer convenience, so storage may be absent: on by default. */
function readSound(): boolean {
  try {
    return localStorage.getItem(SOUND_KEY) !== "off";
  } catch {
    return true;
  }
}

type Pages = PageStore<BoardElement>;

function download(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  link.click();
  URL.revokeObjectURL(url);
}

export default function BoardCanvas({ boardUuid }: { boardUuid: string }) {
  const [board, setBoard] = useState<BoardDetail | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [api, setApi] = useState<BoardApi | null>(null);
  // Our loading screen stays over the canvas until Excalidraw's scene is in (its own is hidden).
  const [sceneReady, setSceneReady] = useState(false);
  useEffect(() => {
    if (api && !api.getAppState().isLoading) setSceneReady(true);
  }, [api]);
  // A new key mounts a new canvas; `carried` is what it starts with besides the page.
  const [canvas, setCanvas] = useState<{ key: number; carried: CarriedState }>({ key: 0, carried: {} });
  const [pageIndex, setPageIndex] = useState(0);
  const [background, setBackground] = useState<BoardBackground>("white");
  const [presenting, setPresenting] = useState(false);
  const [reveal, setReveal] = useState(false);
  const [screen, setScreen] = useState({ index: 0, count: 1 });
  const containerRef = useRef<HTMLDivElement>(null);
  const pages = useRef<Pages>(new PageStore<BoardElement>());
  const { user } = useAuth();
  const apiRef = useRef<BoardApi | null>(null);
  apiRef.current = api;
  const pageIndexRef = useRef(0);
  pageIndexRef.current = pageIndex;
  const backgroundRef = useRef(background);
  backgroundRef.current = background;
  const boardRef = useRef<BoardDetail | null>(null);
  boardRef.current = board;

  const access = useMemo<PageAccess>(
    () => ({
      read: (page) => {
        // Never `[]` for a page not arrived: that is a blank page, and a save of it erases the page.
        if (pages.current.isPending(page)) throw new Error(`page ${page} has not arrived`);
        return pages.current.get(page) ?? [];
      },
      replace: (page, scene) => {
        const elements = restorePage(parseScene({ uuid: page, position: 0, version: 0, scene, background_file: null }).elements);
        pages.current.set(page, elements);
        const shown = boardRef.current?.pages[pageIndexRef.current]?.uuid;
        if (apiRef.current && shown === page) loadPage(apiRef.current, elements);
        return elements;
      },
      hash: sceneVersion,
      document: (elements) => pageDocument(elements, backgroundRef.current),
      reload: (fresh) => {
        pages.current = PageStore.of(fresh.pages, restorePage);
        const index = Math.min(pageIndexRef.current, fresh.pages.length - 1);
        if (apiRef.current) loadPage(apiRef.current, pages.current.get(fresh.pages[index].uuid) ?? []);
        setPageIndex(index);
        setBoard(fresh);
      },
    }),
    [],
  );
  const session = useBoardSession(board, user?.uuid ?? null, access);
  const sessionRef = useRef(session);
  sessionRef.current = session;

  // The element library: kept in this browser, and filled from libraries.excalidraw.com.
  useBoardLibrary(api);
  useEffect(() => {
    // That site sends a library back to the window it was opened from BY NAME;
    // unnamed, it opened the board again in a new tab and the library went there.
    if (!window.name) window.name = "whiteboard";
  }, []);
  // Folded by default, and remembered per browser: the board as clean as it can be (owner, 2026-10-03).
  const [showPages, setShowPagesState] = useState(() => {
    try {
      return localStorage.getItem("whiteboard.pages.open") === "1";
    } catch {
      return false;
    }
  });
  const setShowPages = (next: (shown: boolean) => boolean) =>
    setShowPagesState((shown) => {
      const value = next(shown);
      try {
        localStorage.setItem("whiteboard.pages.open", value ? "1" : "0");
      } catch {
        // ponytail: a private window just forgets it.
      }
      return value;
    });
  const [pagesBusy, setPagesBusy] = useState(false);
  // Waiting for a page still on its way: moving is held, and nothing else is.
  const [arriving, setArriving] = useState(false);
  // Story 6: the rich object selected (its «تعديل» button), and the one being edited.
  const [richSelected, setRichSelected] = useState<{ id: string; data: RichData } | null>(null);
  const [richEdit, setRichEdit] = useState<{ data: RichData; elementId: string | null } | null>(null);
  const [richSaving, setRichSaving] = useState(false);
  // An import keeps adding pages after the teacher leaves the board unless it asks.
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true; // again after React's dev double mount
    return () => void (mounted.current = false);
  }, []);
  // Story 4: the import the teacher started, followed here so closing the menu never stops it.
  const [importView, setImportView] = useState<ImportView>({ phase: "idle" });
  const [notice, setNotice] = useState<string | null>(null);
  // Effects (US10, US12): a display layer, never the page.
  const [celebration, setCelebration] = useState<{ kind: Celebration; id: number } | null>(null);
  const [balloons, setBalloons] = useState<number | null>(null);
  const [attention, setAttention] = useState<number | null>(null);
  const [stunt, setStunt] = useState<{ kind: StuntKind; id: number } | null>(null);
  // Presenter tools (US8): display layers too.
  const [spotlight, setSpotlight] = useState(false);
  const [timer, setTimer] = useState<{ minutes: number; id: number } | null>(null);
  const [calculator, setCalculator] = useState(false);
  const endSpotlight = useCallback(() => setSpotlight(false), []);
  // Teaching tools (US9).
  const [passing, setPassing] = useState<PassingTool | null>(null);
  const [template, setTemplate] = useState<TemplateName | null>(null);
  const endPassing = useCallback(() => setPassing(null), []);
  // Geometry instruments (US9, FR-029) — in page units, so they need the view.
  const [view, setView] = useState<View>({ scrollX: 0, scrollY: 0, zoom: 1 });
  const [instrument, setInstrument] = useState<{ kind: InstrumentKind; id: number } | null>(null);
  const endInstrument = useCallback(() => setInstrument(null), []);
  const drawAlong = useCallback((points: [number, number][]) => {
    if (apiRef.current) addStroke(apiRef.current, points);
  }, []);

  /** A name on the page's frame; the picture behind the canvas follows it (`PageCover`). */
  const chooseTemplate = (name: TemplateName | null, undoable = true) => {
    if (!api) return;
    setPageTemplate(api, name, undoable);
    setTemplate(name);
  };

  // The template buttons show the page shown, read from the page itself.
  useEffect(() => {
    if (api) setTemplate(pageTemplate(api));
  }, [api, pageIndex]);

  // «القلم السحري»: on while its pen is the one in hand; any other tool turns it off.
  const [magic, setMagic] = useState(false);
  // The stroke being drawn, by id: a stroke inside the page is placed BEFORE the
  // page's frame, so «the last element» is never it (caught in review).
  const drawing = useRef<string | null>(null);
  const choosePen = (id: PenId) => {
    const pen = PENS.find((p) => p.id === id);
    if (api && pen) applyPen(api, pen);
    setMagic(id === "magic");
  };
  const [trail, setTrail] = useState<TrailStyle>("off");
  const [sound, setSound] = useState(readSound);

  const celebrate = (kind: Celebration) => {
    // A new id restarts the effect even when the same button is pressed twice.
    setCelebration({ kind, id: Date.now() });
  };

  const startEffect = (kind: Effect) => {
    switch (kind) {
      case "applause":
        celebrate("applause");
        if (sound) void playRecording("hurray");
        return;
      case "party":
        celebrate("party");
        if (sound) {
          void playRecording("blower");
          playFanfare();
        }
        return;
      case "stars":
        celebrate("stars");
        if (sound) playChime();
        return;
      case "hearts":
      case "thumbs":
        celebrate(kind);
        if (sound) playChime();
        return;
      case "bubbles":
        celebrate("bubbles");
        if (sound) playBubbles();
        return;
      case "airplane":
      case "egg":
      case "tomato":
      case "brick":
      case "whistle":
      case "stick":
      case "warning":
      case "wrong":
      case "yellowCard":
      case "redCard":
        setStunt({ kind, id: Date.now() });
        window.clearTimeout(landing);
        if (sound) STUNT_SOUNDS[kind]();
        return;
      case "balloons":
        setBalloons(Date.now());
        return;
      case "attention":
        setAttention(Date.now());
        if (sound) playGavel();
        return;
      case "drumroll":
        // The roll builds the suspense; the stars land when it stops.
        if (!sound) return startEffect("stars");
        void playRecording("drumroll").then((ended) => ended && startEffect("stars"));
        return;
    }
  };

  const endBalloons = useCallback(() => setBalloons(null), []);
  const endStunt = useCallback(() => setStunt(null), []);
  const endAttention = useCallback(() => setAttention(null), []);

  /** Stamp a sticker on the page shown — saved with it, like anything drawn. */
  const stamp = async (name: StickerName) => {
    if (!api) return;
    const id = stickerFileId(name);
    addPictures(api, await pictures.take([id]));
    placeSticker(api, id, name, (Math.random() - 0.5) * 120);
  };

  const changeSound = (on: boolean) => {
    setSound(on);
    try {
      localStorage.setItem(SOUND_KEY, on ? "on" : "off");
    } catch {
      // A private window: the choice lasts this visit only.
    }
  };
  const pictures = useMemo(
    () =>
      createPictureCache((id) => {
        // A sticker is drawn from its name; only real files come from the server.
        const sticker = stickerOf(id);
        if (sticker) return renderSticker(sticker);
        const template = templateOf(id);
        return template ? renderTemplate(template) : boards.fileBytes(boardUuid, id);
      }),
    [boardUuid],
  );

  /*
   * The board opens on its first screen (`firstScenes`) and the rest of its pages
   * arrive behind it, in ONE request for the whole board: a page's scene and its
   * version come from the same response, and a jump past the first screen waits
   * no longer than the whole board took before.
   *
   * Anything that needs every page waits here first: turning to a page not
   * arrived, a change to the pages, the PDF, a thumbnail. A failure is retried
   * twice, then by the next thing that waits.
   *
   * ponytail: one background request; chunk it by page if a jump mid-download is measured slow.
   */
  const rest = useRef<Promise<void> | null>(null);
  const whenArrived = useCallback((): Promise<void> => {
    if (pages.current.pending() === 0) return Promise.resolve();
    rest.current ??= (async () => {
      let whole: BoardDetail | null = null;
      for (let attempt = 0; !whole; attempt++) {
        try {
          whole = await boards.show(boardUuid);
        } catch (error) {
          if (attempt >= 2) throw error;
          await new Promise((resolve) => setTimeout(resolve, 2000 * (attempt + 1)));
        }
      }
      // Only pages still waiting, of this board as shown: a page added, written or deleted meanwhile keeps its own.
      const known = new Set((boardRef.current?.pages ?? []).map((page) => page.uuid));
      const filled = pages.current.fill(whole.pages.filter((page) => known.has(page.uuid)));
      if (filled.length > 0) {
        const byUuid = new Map(filled.map((page) => [page.uuid, page]));
        setBoard((shown) => shown && { ...shown, pages: shown.pages.map((page) => byUuid.get(page.uuid) ?? page) });
        sessionRef.current.arrived(filled);
        // Their pictures too, before the class needs them (T072).
        void pictures.prefetch([], pages.current.pictureIds(filled.map((page) => page.uuid)));
      }
      // A page deleted elsewhere while it was on its way: dropped from the list,
      // and nothing else touched — a reload here put an older copy of the page
      // shown back on screen, and the autosave saved it over newer work (caught in review).
      const arrived = new Set(whole.pages.map((page) => page.uuid));
      const gone = [...known].filter((uuid) => pages.current.isPending(uuid) && !arrived.has(uuid));
      if (gone.length > 0) {
        for (const uuid of gone) pages.current.delete(uuid);
        const shown = boardRef.current?.pages[pageIndexRef.current]?.uuid;
        const list = (boardRef.current?.pages ?? []).filter((page) => !gone.includes(page.uuid));
        setBoard((current) => current && { ...current, pages: current.pages.filter((page) => !gone.includes(page.uuid)) });
        setPageIndex(Math.max(0, list.findIndex((page) => page.uuid === shown)));
      }
    })().catch((error: unknown) => {
      if (rest.current === loading) rest.current = null;
      throw error;
    });
    const loading = rest.current;
    return loading;
  }, [boardUuid, pictures]);

  // The face first, then the pages: text measured before Cairo loads is clipped.
  useEffect(() => {
    let alive = true;
    rest.current = null; // another board's pages are not this one's
    installTextMetrics();
    Promise.all([ensureArabicFont(), boards.show(boardUuid, { firstScenes: true })])
      .then(([, detail]) => {
        if (!alive) return;
        // Restored page by page as each is first read; every picture's bytes now,
        // before the class needs them (T072).
        pages.current = PageStore.of(detail.pages, restorePage);
        const first = detail.pages.slice(0, PICTURE_REACH + 1).map((page) => page.uuid);
        void pictures.prefetch(pages.current.pictureIds(first), pages.current.pictureIds(), () => alive);
        setBackground(detail.background);
        setBoard(detail);
        // The rest of the pages, behind the first screen.
        void whenArrived().catch(() => undefined);
        warmWindows();
      })
      .catch((error: unknown) => {
        if (alive) setFailure(error instanceof ApiError && error.status === 404 ? WB.notFound : WB.loadFailed);
      });
    return () => {
      alive = false;
    };
  }, [boardUuid, pictures, whenArrived]);

  /** Fit one screen of the page: `screen`, or the one the teacher is on (a resize keeps it). */
  const fit = useCallback(
    (screen?: number) => {
      const box = containerRef.current?.getBoundingClientRect();
      if (api && box) fitToFrame(api, box.width, box.height, screen ?? currentScreen(api, box.height));
    },
    [api],
  );

  // Excalidraw settles its own viewport after mount, so fit on the next frame too.
  useEffect(() => {
    if (!api) return;
    const refit = () => fit();
    refit();
    const frame = requestAnimationFrame(refit);
    window.addEventListener("resize", refit);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener("resize", refit);
    };
  }, [api, fit]);

  /** Down (or up) a screen — growing the page when the teacher goes past its end. */
  const moveScreen = useCallback(
    (delta: number) => {
      const box = containerRef.current?.getBoundingClientRect();
      if (!api || !box) return;
      const target = currentScreen(api, box.height) + delta;
      // Only the lock holder grows a page; anyone else just looks through it.
      showScreen(api, box.width, box.height, session.held ? target : Math.min(target, pageScreens(api) - 1));
    },
    [api, session.held],
  );

  /**
   * The screen counter, and the view while an instrument needs it, follow the
   * page. Called on every scroll AND every scene change: switching to a page of
   * another height scrolls to the same spot, and Excalidraw sends no scroll event
   * for that. Unchanged state keeps its object, so a plain scroll re-renders
   * nothing here — the cover outside the page follows on its own (`PageCover`).
   */
  const instrumentOpen = useRef(false);
  instrumentOpen.current = instrument !== null;
  const syncFrame = useCallback(() => {
    if (!api) return;
    const { scrollX, scrollY, zoom } = api.getAppState();
    const box = containerRef.current?.getBoundingClientRect();
    const next = { index: box ? currentScreen(api, box.height) : 0, count: pageScreens(api) };
    setScreen((s) => (sameFields(s, next) ? s : next));
    if (instrumentOpen.current) {
      const nextView = { scrollX, scrollY, zoom: zoom.value };
      setView((v) => (sameFields(v, nextView) ? v : nextView));
    }
  }, [api]);

  useEffect(() => {
    if (!api) return;
    syncFrame();
    return api.onScrollChange(syncFrame);
  }, [api, syncFrame]);

  // An instrument opening needs the view now, not at the next scroll.
  useEffect(() => {
    if (instrument) syncFrame();
  }, [instrument, syncFrame]);

  const pageIds = board?.pages.map((page) => page.uuid) ?? [];

  // A page not shown is hashed once per array: the page map replaces the array
  // whenever that page changes, so 50 pages are not rehashed on every render.
  const versions = useRef(new WeakMap<readonly unknown[], number>());
  const versionOf = (elements: Parameters<typeof sceneVersion>[0]) => {
    let version = versions.current.get(elements);
    if (version === undefined) {
      version = sceneVersion(elements);
      versions.current.set(elements, version);
    }
    return version;
  };

  // The pictures of the page shown and its neighbours, handed to the canvas.
  useEffect(() => {
    if (!api || !board) return;
    let alive = true;
    const near = board.pages.slice(Math.max(0, pageIndex - PICTURE_REACH), pageIndex + PICTURE_REACH + 1);
    void pictures
      .take(near.flatMap((page) => pictureIds(pages.current.get(page.uuid) ?? [])))
      // Dropped by a page that moved on, they would read as handed over for ever:
      // an empty picture box on that page until the board was reopened.
      .then((found) => (alive ? addPictures(api, found) : pictures.release(found.map((picture) => picture.id))));
    return () => {
      alive = false;
    };
  }, [api, board, pageIndex, pictures]);

  /** Keep what is on screen in the page map before anything replaces it. */
  const keepShown = useCallback(() => {
    // Read NOW, never from the render that began the action: an action that
    // waited (the PDF, a page change) filed the page then shown under the page
    // the teacher had moved to meanwhile, and the autosave wrote it over that
    // page's work (caught in review).
    const api = apiRef.current;
    const shown = boardRef.current?.pages[pageIndexRef.current]?.uuid;
    // Copies, not Excalidraw's own objects: its drawing caches are keyed by the
    // element object, so keeping the originals kept every page's bitmaps alive
    // for the whole lesson. A page shown again is drawn afresh once.
    if (api && shown) pages.current.set(shown, api.getSceneElementsIncludingDeleted().map((e) => ({ ...e })));
  }, []);

  /** Show `index` of `list` (a list that may have just changed). */
  const showPage = useCallback(
    (list: BoardPagePayload[], index: number) => {
      if (!api) return;
      loadPage(api, pages.current.get(list[index].uuid) ?? []);
      setPageIndex(index);
      fit(0);
    },
    [api, fit],
  );

  const goTo = useCallback(
    (target: number) => {
      // Not while pages change: an import adds pages for a minute and shows the
      // first at the end, keeping the page it started on — moving meanwhile
      // filed one page's drawing under another (caught in review).
      if (!api || !board || pagesBusy || arriving || target < 0 || target >= board.pages.length || target === pageIndex) return;
      // A page still on its way: wait for it (moving meanwhile is held, as during a page change).
      // Its own hold, never `pagesBusy`: releasing that mid-import let the page
      // turn under an import still running (caught in review).
      if (pages.current.isPending(board.pages[target].uuid)) {
        const uuid = board.pages[target].uuid;
        setArriving(true);
        setNotice(WB.pageArriving);
        whenArrived()
          .then(
            () => {
              setNotice(null);
              setResume(uuid);
            },
            () => setNotice(WB.loadFailed), // no retry loop: the next turn asks again
          )
          .finally(() => setArriving(false));
        return;
      }
      keepShown();
      if (pictures.held() > MAX_HELD_PICTURES) {
        const carried = carriedState(api);
        pictures.forget();
        setApi(null);
        setPageIndex(target);
        setCanvas(({ key }) => ({
          key: key + 1,
          carried,
        }));
        return;
      }
      showPage(board.pages, target);
    },
    [api, board, pageIndex, pagesBusy, arriving, keepShown, showPage, pictures, whenArrived],
  );

  // The page asked for while it was on its way, once it has come — by uuid, so
  // a list changed meanwhile still finds it (or drops it, if deleted).
  const [resume, setResume] = useState<string | null>(null);
  useEffect(() => {
    if (resume === null || pagesBusy || arriving || !board) return;
    setResume(null);
    const target = board.pages.findIndex((page) => page.uuid === resume);
    if (target >= 0) goTo(target);
  }, [resume, pagesBusy, arriving, board, goTo]);

  // Next / previous page from the keyboard — never while typing.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.target instanceof Element && event.target.closest("textarea, input, select, [contenteditable]")) return;
      if (event.key === "PageDown") goTo(pageIndex + 1);
      if (event.key === "PageUp") goTo(pageIndex - 1);
      // The arrows move a SELECTED element in Excalidraw; with nothing selected they
      // turn the page, in reading order: left (where an Arabic line ends) is next.
      if (api && Object.keys(api.getAppState().selectedElementIds).length === 0) {
        if (event.key === "ArrowLeft") goTo(pageIndex + 1);
        if (event.key === "ArrowRight") goTo(pageIndex - 1);
        // Up and down: the screens of this page, growing it past its end.
        if (event.key === "ArrowDown") moveScreen(1);
        if (event.key === "ArrowUp") moveScreen(-1);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [api, goTo, pageIndex, moveScreen]);

  // The app keeps a scrollbar on <html> on every page; on the tab the class watches
  // it is a grey strip down the side of the board. Removed while the board is open.
  useEffect(() => {
    const root = document.documentElement;
    const previous = { overflow: root.style.overflow, gutter: root.style.scrollbarGutter };
    root.style.overflow = "hidden";
    root.style.scrollbarGutter = "auto";
    return () => {
      root.style.overflow = previous.overflow;
      root.style.scrollbarGutter = previous.gutter;
    };
  }, []);

  // «عرض»: the tools come back only while the pointer is near the top edge.
  useEffect(() => {
    if (!presenting) {
      setReveal(false);
      return;
    }
    const onMove = (event: PointerEvent) => setReveal(event.clientY <= REVEAL_TOP_PX);
    window.addEventListener("pointermove", onMove);
    return () => window.removeEventListener("pointermove", onMove);
  }, [presenting]);

  // Each panel shown, folded or hidden until the pointer nears it — this browser only.
  const [panelModes, setPanelModes] = useState(readPanelModes);
  const changePanelMode = (id: PanelId, mode: PanelMode) =>
    setPanelModes((current) => {
      const next = { ...current, [id]: mode };
      writePanelModes(next);
      return next;
    });

  const changeBackground = (next: BoardBackground) => {
    if (!api || !board || next === background) return;
    applyBackground(api, background, next);
    setBackground(next);
    boards.update(board.uuid, { background: next }).catch(() => undefined);
  };

  const rename = async (title: string) => {
    if (!board) return;
    const updated = await boards.update(board.uuid, { title });
    setBoard({ ...board, title: updated.title });
  };

  const exportCurrent = async (kind: ExportKind) => {
    if (!api || !board) return;
    // A file is the page alone: the template goes in as pictures, so its file must be on the canvas.
    if (template) addPictures(api, await pictures.take([templateFileId(template)]));
    const blob = await exportPage(api, kind, background);
    download(blob, `${board.title} - ${pageIndex + 1}.${kind}`);
  };

  /** One page change at a time; a refused order means another tab moved pages, so they are reloaded. */
  const pageChange = async (change: () => Promise<void>) => {
    setPagesBusy(true);
    setNotice(null);
    try {
      // Every page first: a change shows a neighbour, and one not arrived would show blank.
      await whenArrived();
      await session.settle();
      await change();
    } catch (error) {
      const code = error instanceof ApiError ? (error.body as { code?: string } | null)?.code : undefined;
      if (code === "pages_changed" && board) access.reload(await boards.show(board.uuid));
      setNotice(code && code in WB.errors ? WB.errors[code as keyof typeof WB.errors] : WB.pagesFailed);
    } finally {
      setPagesBusy(false);
    }
  };

  /** A page the server made, shown right after `afterUuid`. */
  const insertPage = (page: BoardPagePayload, afterUuid: string, elements = restorePage(parseScene(page).elements)) => {
    if (!board) return;
    keepShown();
    pages.current.set(page.uuid, elements);
    session.track(page.uuid, page.version, elements);
    const list = [...board.pages];
    const at = list.findIndex((p) => p.uuid === afterUuid) + 1;
    list.splice(at, 0, page);
    setBoard({ ...board, pages: list });
    showPage(list, at);
  };

  const addPage = (after: string) =>
    pageChange(async () => {
      if (!board) return;
      // A new page carries on in the template of the page the teacher is on (owner, 2026-10-02).
      const carried = template;
      insertPage(await boards.addPage(board.uuid, { tab: session.tab(), after }), after);
      // Not an undo step: the new page simply starts that way.
      if (carried) chooseTemplate(carried, false);
    });

  const duplicatePage = (uuid: string) =>
    pageChange(async () => {
      if (!board) return;
      insertPage(await boards.addPage(board.uuid, { tab: session.tab(), duplicate_of: uuid }), uuid);
    });

  const deletePage = (uuid: string) =>
    pageChange(async () => {
      if (!board) return;
      keepShown();
      await boards.deletePage(board.uuid, session.tab(), uuid);
      // Only now: a refused delete must leave the page saving as before.
      session.pageRemoved(uuid);
      pages.current.delete(uuid);
      const shown = board.pages[pageIndex].uuid;
      const list = board.pages.filter((p) => p.uuid !== uuid);
      setBoard({ ...board, pages: list });
      showPage(list, shown === uuid ? Math.min(pageIndex, list.length - 1) : list.findIndex((p) => p.uuid === shown));
    });

  const reorderPages = (order: string[]) =>
    pageChange(async () => {
      if (!board) return;
      const shown = board.pages[pageIndex].uuid;
      await boards.reorderPages(board.uuid, session.tab(), order);
      const byUuid = new Map(board.pages.map((p) => [p.uuid, p]));
      const list = order.flatMap((uuid, i) => {
        const page = byUuid.get(uuid);
        return page ? [{ ...page, position: i + 1 }] : [];
      });
      setBoard({ ...board, pages: list });
      setPageIndex(list.findIndex((p) => p.uuid === shown));
    });

  /** After a conflict: the teacher's copy becomes a new page after the contested one, which takes the server's copy. */
  const keepMineAsNewPage = () =>
    pageChange(async () => {
      if (!board || !session.conflict) return;
      const contested = session.conflict.page;
      keepShown();
      const mine = pages.current.get(contested) ?? [];
      // The new page first: if it cannot be made, nothing has been given up yet.
      const page = await boards.addPage(board.uuid, { tab: session.tab(), after: contested });
      session.takeServer();
      const elements = reframe(mine, page.uuid);
      insertPage(page, contested, elements);
      session.adopt(page.uuid, elements);
    });

  /**
   * A picture, or every page of a PDF read IN THIS BROWSER (the server converts
   * nothing — owner, 2026-10-02), added as pages after the page shown. For each
   * page the picture is uploaded FIRST, so a refused upload leaves no empty page;
   * the page is then made and saved with the picture on it, off screen. The
   * first new page is shown at the end, after whatever the teacher drew
   * meanwhile on the page shown is kept.
   */
  const importFile = (file: File) =>
    pageChange(async () => {
      if (!board || !api) return;
      const isPdf = file.type === "application/pdf" || /\.pdf$/i.test(file.name);
      const list = [...board.pages];
      const at = pageIndex + 1;
      let added = 0;
      let capped: number | undefined;
      const addPage = async (picture: File, width: number, height: number) => {
        if (!mounted.current) throw new Error("The board was closed.");
        const id = await uploadBoardImage(board.uuid, session.tab(), picture);
        const page = await boards.addPage(board.uuid, { tab: session.tab(), after: list[at + added - 1].uuid });
        const blank = restorePage(parseScene(page).elements);
        session.track(page.uuid, page.version, blank);
        const elements = withPagePicture(blank, id, width, height);
        pages.current.set(page.uuid, elements);
        session.adopt(page.uuid, elements);
        list.splice(at + added, 0, page);
        added++;
      };
      setImportView({ phase: "reading" });
      try {
        if (isPdf) {
          for await (const page of pdfPages(file)) {
            setImportView({ phase: "converting", page: page.number, total: page.total });
            await addPage(page.file, page.width, page.height);
            if (page.inFile > page.total) capped = page.total;
          }
        } else {
          const bitmap = await createImageBitmap(file);
          const size = { width: bitmap.width, height: bitmap.height };
          bitmap.close();
          await addPage(file, size.width, size.height);
        }
        setImportView({ phase: "done", pages: added, capped });
      } catch (error) {
        const message =
          error instanceof ApiError
            ? (WB.errors[(error.body as { code?: string } | null)?.code as keyof typeof WB.errors] ?? WB.pagesFailed)
            : error instanceof ImageRefused
              ? WB.imageFailed
              : isPdf
                ? WB.errors.corrupt
                : WB.errors.unsupported;
        setImportView({ phase: "failed", message });
        // A server refusal also goes to the page notice (and reloads on `pages_changed`).
        if (error instanceof ApiError) throw error;
      } finally {
        if (added > 0) {
          keepShown();
          setBoard((current) => current && { ...current, pages: list }); // a rename meanwhile stays
          showPage(list, at);
        }
      }
    });

  /**
   * A table or an equation saved (story 6): its picture is drawn and uploaded
   * FIRST — a refused upload changes nothing on the page — then put in place, or
   * swapped into the same element when it is being edited. An equation takes
   * the pen's colour, so it reads on a blackboard as on white.
   */
  const saveRich = async (data: RichData, elementId: string | null) => {
    if (!api || !board || richSaving) return;
    setRichSaving(true);
    try {
      const { blob, width, height } =
        data.kind === "table"
          ? await renderTable(data)
          : data.kind === "graph"
            ? await renderGraph(data)
            : await renderMath(data.latex, data.display, api.getAppState().currentItemStrokeColor);
      const file = new File([blob], `${data.kind}.png`, { type: "image/png" });
      const id = await uploadBoardImage(board.uuid, session.tab(), file, async (bytes) => bytes);
      const dataURL = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
      });
      addPictures(api, [{ id, dataURL, mimeType: "image/png" }]);
      pictures.given(id);
      if (elementId && !replaceRichObject(api, elementId, id, width, height, data)) setNotice(WB.table.gone);
      if (!elementId) placeRichObject(api, id, width, height, data);
      setRichEdit(null);
    } catch (error) {
      setNotice(error instanceof MathError ? WB.math.invalid : error instanceof GraphError ? WB.graph.invalid : WB.imageFailed);
    } finally {
      setRichSaving(false);
    }
  };

  // Story 6: a table copied from Excel or Google Sheets becomes a table, not a
  // picture of one — caught BEFORE Excalidraw's own paste, which would insert the
  // spreadsheet's screenshot. Plain text and pictures pass through untouched.
  useEffect(() => {
    const root = containerRef.current;
    if (!root || !session.held) return;
    const onPaste = (event: ClipboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (target?.closest("input, textarea, [contenteditable='true']")) return;
      const table = parseClipboardTable(event.clipboardData?.getData("text/html") ?? "", event.clipboardData?.getData("text/plain") ?? "");
      if (!table) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      void saveRich(table, null);
    };
    root.addEventListener("paste", onPaste, true);
    return () => root.removeEventListener("paste", onPaste, true);
  });

  /** Story 5: every page, drawn off the canvas from the page map, into one PDF. */
  const renderBoardPdf = async (onPage: (done: number) => void): Promise<Blob> => {
    if (!board) throw new Error("no board");
    await whenArrived(); // every page, or the PDF has blank ones
    keepShown(); // what is on screen now is in the PDF
    const list = boardRef.current?.pages ?? board.pages;
    const paper = backgroundRef.current;
    // A snapshot: a reload from another tab mid-draw must not turn pages blank.
    const snapshot = new Map(pages.current);
    return boardPdf(
      list.length,
      async (index) => {
        const elements = snapshot.get(list[index].uuid) ?? [];
        return pageImage(elements, await pictures.peek(pagePictureIds(elements)), paper);
      },
      onPage,
    );
  };

  const thumbnail = useCallback(
    async (uuid: string) => {
      if (pages.current.isPending(uuid)) await whenArrived();
      const shownUuid = board?.pages[pageIndex]?.uuid;
      const elements = api && uuid === shownUuid ? api.getSceneElements() : (pages.current.get(uuid) ?? []);
      return pageThumbnail(elements, await pictures.peek(pictureIds(elements)), backgroundRef.current);
    },
    [api, board, pageIndex, pictures, whenArrived],
  );

  if (failure) {
    return (
      <p role="alert" className="p-6 text-sm text-danger-ink">
        {failure}
      </p>
    );
  }

  if (!board) return <BoardLoading />;

  return (
    <div
      ref={containerRef}
      className="wb-board relative h-dvh w-full"
      data-presenting={presenting ? "true" : undefined}
      data-reveal={reveal ? "true" : undefined}
    >
      {/* «عرض» hides Excalidraw's whole UI layer; scoped to this board only. */}
      <style>{`
        .wb-board[data-presenting="true"]:not([data-reveal="true"]) .layer-ui__wrapper,
        .wb-board[data-presenting="true"]:not([data-reveal="true"]) .layer-ui__wrapper * { visibility: hidden !important; }
        .wb-board .LoadingMessage { display: none !important; }
      `}</style>
      {!sceneReady && <BoardLoading overlay />}
      {!presenting && <PanelVisibility root={containerRef} modes={panelModes} onMode={changePanelMode} layout={`${session.held}:${showPages}`} />}
      <Excalidraw
        key={canvas.key}
        excalidrawAPI={setApi}
        UIOptions={UI_OPTIONS}
        langCode="ar-SA"
        viewModeEnabled={!session.held}
        // Every way a picture arrives (tool, paste, drop) asks for its id here: it
        // is uploaded first, and the page names OUR file (T073).
        generateIdForFile={async (file: File) => {
          try {
            const id = await uploadBoardImage(board.uuid, session.tab(), file);
            pictures.given(id);
            return id;
          } catch (error) {
            setNotice(WB.imageFailed);
            throw error;
          }
        }}
        onChange={(elements, appState) => {
          if (!sceneReady && !appState.isLoading) setSceneReady(true);
          // The template buttons follow the page, an undo included (a cheap find;
          // React skips the render when the name is unchanged).
          setTemplate(frameTemplate(elements));
          if (appState.cursorButton !== "down") syncFrame(); // a stroke never changes the frame
          // Another tool turns it off — not the eraser, which a pen's back end picks for a moment.
          if (magic && appState.activeTool.type !== "freedraw" && appState.activeTool.type !== "eraser") setMagic(false);
          // The magic pen acts when the pen LIFTS, on the stroke just finished — outside
          // this callback, since it changes the scene this callback reports.
          if (appState.cursorButton === "down") {
            if (appState.newElement?.type === "freedraw") drawing.current = appState.newElement.id;
          } else if (drawing.current) {
            const finished = drawing.current;
            drawing.current = null;
            if (magic && api && session.held) queueMicrotask(() => magicStroke(api, finished));
          }
          // The «تعديل» button follows a single selected table or equation.
          if (api && appState.cursorButton !== "down") {
            const next = Object.keys(appState.selectedElementIds).length === 1 ? selectedRichObject(api) : null;
            setRichSelected((current) => (current?.id === next?.id && current?.data === next?.data ? current : next));
          }
          // Mid-stroke changes wait for the stroke to end (R-08).
          if (!session.held || appState.cursorButton === "down") return;
          const shown = board.pages[pageIndex]?.uuid;
          if (shown) session.changed(shown, elements);
        }}
        initialData={{
          elements: pages.current.get(pageIds[pageIndex] ?? pageIds[0]) ?? [],
          appState: { ...initialAppState(background, window.innerWidth, window.innerHeight), ...canvas.carried },
        }}
        renderTopRightUI={() => (
          <BoardToolbar
            title={board.title}
            background={background}
            canEdit={session.held}
            pageIndex={pageIndex}
            pageCount={board.pages.length}
            presenting={presenting}
            onPrevious={() => goTo(pageIndex - 1)}
            onNext={() => goTo(pageIndex + 1)}
            onRename={rename}
            onBackground={changeBackground}
            onExport={exportCurrent}
            onTogglePresenting={() => setPresenting((value) => !value)}
            menus={[
              {
                id: "tools",
                label: WB.menus.tools,
                content: (
                  <TeachingBar
                    canEdit={session.held}
                    template={template}
                    open={passing}
                    onTemplate={chooseTemplate}
                    onPen={choosePen}
                    magic={magic}
                    onTool={(tool) => setPassing((current) => (current === tool ? null : tool))}
                    instrument={instrument?.kind ?? null}
                    onInstrument={(kind) => setInstrument((current) => (current?.kind === kind ? null : { kind, id: Date.now() }))}
                    onTable={() => setRichEdit({ data: blankTable(), elementId: null })}
                    onMath={() => setRichEdit({ data: { kind: "math", v: 1, latex: "", display: true }, elementId: null })}
                    onGraph={() => setRichEdit({ data: blankGraph(), elementId: null })}
                    onCalculator={() => setCalculator((shown) => !shown)}
                  />
                ),
              },
              {
                id: "present",
                label: WB.menus.present,
                content: (
              <PresenterBar
                spotlight={spotlight}
                timerRunning={timer !== null}
                onLaser={() => api && startLaser(api)}
                onSpotlight={() => setSpotlight((on) => !on)}
                onTimer={(minutes) => setTimer({ minutes, id: Date.now() })}
              />
                ),
              },
              {
                id: "encourage",
                label: WB.menus.encourage,
                content: (
              <EffectsBar
                sound={sound}
                trail={trail}
                onEffect={startEffect}
                onSticker={session.held ? stamp : undefined}
                onSound={changeSound}
                onTrail={setTrail}
              />
                ),
              },
              { id: "layout", label: WB.menus.layout, content: <PanelModesMenu modes={panelModes} onMode={changePanelMode} /> },
              ...(session.held ? [{ id: "import", label: WB.menus.import, content: <ImportPanel view={importView} onFile={importFile} /> }] : []),
              ...(board.can.export
                ? [{ id: "lesson", label: WB.menus.lesson, content: <LessonExportPanel board={board} pageCount={board.pages.length} renderPdf={renderBoardPdf} /> }]
                : []),
            ]}
            screen={screen}
            onScreen={moveScreen}
            pagesOpen={showPages}
            onTogglePages={() => setShowPages((value) => !value)}
            status={
              <>
                {session.held && !session.handoverRequested ? (
                  <SaveIndicator state={session.saveState} />
                ) : (
                  <LockBanner
                    heldBy={session.heldBy}
                    canTake={board.can.take_lock && !session.held}
                    taking={session.taking}
                    handoverRequested={session.handoverRequested}
                    onTake={session.take}
                  />
                )}
                {notice && (
                  <p role="alert" className="text-xs text-danger-ink">
                    {notice}
                  </p>
                )}
                {session.refusal && (
                  <p role="alert" className="text-xs text-danger-ink">
                    {WB.errors[session.refusal as keyof typeof WB.errors] ?? WB.saveState.failed}
                  </p>
                )}
              </>
            }
          />
        )}
      >
        <AcademyLibrary api={api} canEdit={session.held} />
      </Excalidraw>
      {api && <PageCover api={api} background={background} template={template} />}
      {showPages && !presenting && (
        // Over the canvas, on the side the toolbar is not, and hidden for «عرض»: the class sees the whole tab.
        <div data-panel="pages" className="absolute bottom-16 start-2 top-16" style={{ zIndex: 5 }}>
          <PagesSidebar
            pages={board.pages.map((page, index) => ({
              uuid: page.uuid,
              // The page shown is read live, so its picture follows the drawing.
              // A page not restored yet keys its thumbnail by the server's version — reading it here would restore all of them.
              version:
                index === pageIndex && api
                  ? sceneVersion(api.getSceneElementsIncludingDeleted())
                  : pages.current.isRestored(page.uuid)
                    ? versionOf(pages.current.get(page.uuid) ?? [])
                    : page.version,
            }))}
            current={pageIndex}
            canEdit={session.held}
            busy={pagesBusy}
            thumbnail={thumbnail}
            onSelect={goTo}
            onAdd={addPage}
            onDuplicate={duplicatePage}
            onDelete={deletePage}
            onReorder={reorderPages}
            onCollapse={() => setShowPages(() => false)}
          />
        </div>
      )}
      {!showPages && !presenting && (
        <div data-panel="pages-tab" className="absolute start-2 top-1/2 -translate-y-1/2" style={{ zIndex: 5 }}>
          <PagesTab current={pageIndex} count={board.pages.length} onOpen={() => setShowPages(() => true)} />
        </div>
      )}
      {session.held && richSelected && !richEdit && (
        <button
          type="button"
          onClick={() => setRichEdit({ data: richSelected.data, elementId: richSelected.id })}
          className="absolute top-16 left-1/2 z-20 -translate-x-1/2 rounded-full bg-accent px-4 py-1.5 text-sm font-medium text-accent-foreground shadow-lg"
        >
          {WB[richSelected.data.kind].edit}
        </button>
      )}
      {richEdit?.data.kind === "table" && (
        <TableEditor
          initial={richEdit.data}
          saving={richSaving}
          onClose={() => setRichEdit(null)}
          onSave={(data) => void saveRich(data, richEdit.elementId)}
        />
      )}
      {richEdit?.data.kind === "graph" && (
        <GraphEditor
          initial={richEdit.data}
          saving={richSaving}
          onClose={() => setRichEdit(null)}
          onSave={(data) => void saveRich(data, richEdit.elementId)}
        />
      )}
      {richEdit?.data.kind === "math" && (
        <MathEditor
          initial={richEdit.data}
          saving={richSaving}
          onClose={() => setRichEdit(null)}
          onSave={(data) => void saveRich(data, richEdit.elementId)}
        />
      )}
      {celebration && <Celebrate key={celebration.id} kind={celebration.kind} onDone={() => setCelebration(null)} />}
      {instrument && session.held && (
        <OverlayLayer view={view}>
          <GeometryTool
            key={instrument.id}
            kind={instrument.kind}
            view={view}
            centre={[PAGE_WIDTH / 2, screen.index * PAGE_HEIGHT + PAGE_HEIGHT / 2]}
            onDraw={drawAlong}
            onClose={endInstrument}
          />
        </OverlayLayer>
      )}
      {spotlight && <Spotlight onClose={endSpotlight} />}
      {passing === "magnifier" && <Magnifier background={BACKGROUNDS[background].canvas} template={template} onClose={endPassing} />}
      {passing === "curtain" && <Curtain onClose={endPassing} />}
      {passing === "wheel" && <Wheel board={boardUuid} sound={sound} onClose={endPassing} />}
      {timer && <Timer key={timer.id} minutes={timer.minutes} sound={sound} onClose={() => setTimer(null)} />}
      {calculator && (
        <Calculator
          // «حطّها على السبّورة» is an equation like any other: drawn by MathJax, edited in place.
          onInsert={session.held ? (latex) => void saveRich({ kind: "math", v: 1, latex, display: true }, null) : null}
          onClose={() => setCalculator(false)}
        />
      )}
      {balloons !== null && <BalloonPop key={balloons} sound={sound} onDone={endBalloons} />}
      {stunt && <Stunt key={stunt.id} kind={stunt.kind} onDone={endStunt} />}
      {attention !== null && <AttentionBanner key={attention} onDone={endAttention} />}
      {trail !== "off" && <PointerTrail style={trail} />}
      <ConflictDialog
        open={session.conflict !== null}
        onTakeServer={session.takeServer}
        onKeepMine={keepMineAsNewPage}
        onCancel={session.dismissConflict}
      />
      <Modal
        open={session.restore !== null}
        title={WB.restoreTitle}
        message={session.restore?.verdict === "ask" ? WB.restoreAskMessage : WB.restoreMessage}
        confirmLabel={WB.restoreMine}
        onConfirm={() => session.answerRestore(true)}
        onCancel={() => session.answerRestore(false)}
      />
    </div>
  );
}

/** Same values in every field — lets a state setter keep the old object. */
function sameFields<T extends object>(a: T, b: T): boolean {
  return (Object.keys(b) as (keyof T)[]).every((key) => a[key] === b[key]);
}
