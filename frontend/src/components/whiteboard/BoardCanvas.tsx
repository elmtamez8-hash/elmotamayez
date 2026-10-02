"use client";

import "@excalidraw/excalidraw/index.css";

import { Excalidraw } from "@excalidraw/excalidraw";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";

import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { ensureArabicFont } from "@/lib/whiteboard/arabic-font";
import { boards, parseScene, type BoardDetail, type BoardPagePayload } from "@/lib/whiteboard/api";
import {
  addPictures,
  applyBackground,
  exportPage,
  fitToFrame,
  initialAppState,
  installTextMetrics,
  loadPage,
  pageDocument,
  pageThumbnail,
  pictureIds,
  reframe,
  restorePage,
  sceneVersion,
  type BoardApi,
  type BoardElement,
} from "@/lib/whiteboard/excalidraw-api";
import { BACKGROUNDS, PAGE_HEIGHT, PAGE_WIDTH, type BoardBackground } from "@/lib/whiteboard/page-model";
import { uploadBoardImage } from "@/lib/whiteboard/image-insert";
import { createPictureCache } from "@/lib/whiteboard/picture-cache";
import { WB } from "@/lib/whiteboard/strings";
import { Modal } from "@/components/ui/Modal";
import { BoardToolbar } from "@/components/whiteboard/BoardToolbar";
import { ConflictDialog } from "@/components/whiteboard/ConflictDialog";
import { LockBanner } from "@/components/whiteboard/LockBanner";
import { PagesSidebar } from "@/components/whiteboard/PagesSidebar";
import { SaveIndicator } from "@/components/whiteboard/SaveIndicator";
import { useBoardSession, type PageAccess } from "@/components/whiteboard/useBoardSession";

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
/** Pictures handed to the canvas: the page shown and this many either side. */
const PICTURE_REACH = 2;

type Pages = Map<string, readonly BoardElement[]>;

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
  const [pageIndex, setPageIndex] = useState(0);
  const [background, setBackground] = useState<BoardBackground>("white");
  const [presenting, setPresenting] = useState(false);
  const [reveal, setReveal] = useState(false);
  const [frameRect, setFrameRect] = useState({ left: 0, top: 0, width: 0, height: 0 });
  const containerRef = useRef<HTMLDivElement>(null);
  const pages = useRef<Pages>(new Map());
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
      read: (page) => pages.current.get(page) ?? [],
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
        pages.current = new Map(fresh.pages.map((page) => [page.uuid, restorePage(parseScene(page).elements)]));
        const index = Math.min(pageIndexRef.current, fresh.pages.length - 1);
        if (apiRef.current) loadPage(apiRef.current, pages.current.get(fresh.pages[index].uuid) ?? []);
        setPageIndex(index);
        setBoard(fresh);
      },
    }),
    [],
  );
  const session = useBoardSession(board, user?.uuid ?? null, access);
  const [showPages, setShowPages] = useState(true);
  const [pagesBusy, setPagesBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const pictures = useMemo(() => createPictureCache((id) => boards.fileBytes(boardUuid, id)), [boardUuid]);

  // The face first, then the pages: text measured before Cairo loads is clipped.
  useEffect(() => {
    let alive = true;
    installTextMetrics();
    Promise.all([ensureArabicFont(), boards.show(boardUuid)])
      .then(([, detail]) => {
        if (!alive) return;
        pages.current = new Map(detail.pages.map((page) => [page.uuid, restorePage(parseScene(page).elements)]));
        // Every picture's bytes now, before the class needs them (T072).
        pictures.prefetch([...pages.current.values()].flatMap((elements) => pictureIds(elements)));
        setBackground(detail.background);
        setBoard(detail);
      })
      .catch((error: unknown) => {
        if (alive) setFailure(error instanceof ApiError && error.status === 404 ? WB.notFound : WB.loadFailed);
      });
    return () => {
      alive = false;
    };
  }, [boardUuid, pictures]);

  const fit = useCallback(() => {
    const box = containerRef.current?.getBoundingClientRect();
    if (api && box) fitToFrame(api, box.width, box.height);
  }, [api]);

  // Excalidraw settles its own viewport after mount, so fit on the next frame too.
  useEffect(() => {
    if (!api) return;
    fit();
    const frame = requestAnimationFrame(fit);
    window.addEventListener("resize", fit);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener("resize", fit);
    };
  }, [api, fit]);

  // The mask follows the frame on every scroll and zoom.
  useEffect(() => {
    if (!api) return;
    return api.onScrollChange((scrollX, scrollY, zoom) =>
      setFrameRect({
        left: scrollX * zoom.value,
        top: scrollY * zoom.value,
        width: PAGE_WIDTH * zoom.value,
        height: PAGE_HEIGHT * zoom.value,
      }),
    );
  }, [api]);

  const pageIds = board?.pages.map((page) => page.uuid) ?? [];

  // The pictures of the page shown and its neighbours, handed to the canvas.
  useEffect(() => {
    if (!api || !board) return;
    let alive = true;
    const near = board.pages.slice(Math.max(0, pageIndex - PICTURE_REACH), pageIndex + PICTURE_REACH + 1);
    void pictures
      .take(near.flatMap((page) => pictureIds(pages.current.get(page.uuid) ?? [])))
      .then((found) => alive && addPictures(api, found));
    return () => {
      alive = false;
    };
  }, [api, board, pageIndex, pictures]);

  /** Keep what is on screen in the page map before anything replaces it. */
  const keepShown = useCallback(() => {
    const shown = board?.pages[pageIndex]?.uuid;
    if (api && shown) pages.current.set(shown, api.getSceneElementsIncludingDeleted());
  }, [api, board, pageIndex]);

  /** Show `index` of `list` (a list that may have just changed). */
  const showPage = useCallback(
    (list: BoardPagePayload[], index: number) => {
      if (!api) return;
      loadPage(api, pages.current.get(list[index].uuid) ?? []);
      setPageIndex(index);
      fit();
    },
    [api, fit],
  );

  const goTo = useCallback(
    (target: number) => {
      if (!api || !board || target < 0 || target >= board.pages.length || target === pageIndex) return;
      keepShown();
      showPage(board.pages, target);
    },
    [api, board, pageIndex, keepShown, showPage],
  );

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
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [api, goTo, pageIndex]);

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

  const exportCurrent = async (kind: "png" | "svg") => {
    if (!api || !board) return;
    const blob = await exportPage(api, kind, background);
    download(blob, `${board.title} - ${pageIndex + 1}.${kind}`);
  };

  /** One page change at a time; a refused order means another tab moved pages, so they are reloaded. */
  const pageChange = async (change: () => Promise<void>) => {
    setPagesBusy(true);
    setNotice(null);
    try {
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
      insertPage(await boards.addPage(board.uuid, { tab: session.tab(), after }), after);
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
      session.pageRemoved(uuid);
      await boards.deletePage(board.uuid, session.tab(), uuid);
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
      session.takeServer();
      const page = await boards.addPage(board.uuid, { tab: session.tab(), after: contested });
      const elements = reframe(mine, page.uuid);
      insertPage(page, contested, elements);
      session.adopt(page.uuid, elements);
    });

  const thumbnail = useCallback(
    async (uuid: string) => {
      if (!api) throw new Error("no canvas");
      const shownUuid = board?.pages[pageIndex]?.uuid;
      const elements = uuid === shownUuid ? api.getSceneElements() : (pages.current.get(uuid) ?? []);
      addPictures(api, await pictures.take(pictureIds(elements)));
      return pageThumbnail(api, elements, backgroundRef.current);
    },
    [api, board, pageIndex, pictures],
  );

  if (failure) {
    return (
      <p role="alert" className="p-6 text-sm text-danger-ink">
        {failure}
      </p>
    );
  }

  if (!board) {
    return <p className="p-6 text-sm text-ink-muted">{WB.loading}</p>;
  }

  const excalidrawRoot = containerRef.current?.querySelector(".excalidraw");

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
      `}</style>
      <Excalidraw
        excalidrawAPI={setApi}
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
          // Mid-stroke changes wait for the stroke to end (R-08).
          if (!session.held || appState.cursorButton === "down") return;
          const shown = board.pages[pageIndex]?.uuid;
          if (shown) session.changed(shown, elements);
        }}
        initialData={{
          elements: pages.current.get(pageIds[0]) ?? [],
          appState: initialAppState(background, window.innerWidth, window.innerHeight),
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
      />
      {excalidrawRoot &&
        createPortal(
          <div
            aria-hidden
            className="pointer-events-none absolute"
            style={{
              zIndex: 3,
              left: frameRect.left,
              top: frameRect.top,
              width: frameRect.width,
              height: frameRect.height,
              boxShadow: `0 0 0 100vmax ${BACKGROUNDS[background].canvas}`,
            }}
          />,
          excalidrawRoot,
        )}
      {showPages && !presenting && (
        // Over the canvas, on the side the toolbar is not, and hidden for «عرض»: the class sees the whole tab.
        <div className="absolute bottom-16 start-2 top-16" style={{ zIndex: 5 }}>
          <PagesSidebar
            pages={board.pages.map((page, index) => ({
              uuid: page.uuid,
              // The page shown is read live, so its picture follows the drawing.
              version: sceneVersion(index === pageIndex && api ? api.getSceneElementsIncludingDeleted() : (pages.current.get(page.uuid) ?? [])),
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
          />
        </div>
      )}
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
