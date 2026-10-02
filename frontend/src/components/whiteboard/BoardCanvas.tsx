"use client";

import "@excalidraw/excalidraw/index.css";

import { Excalidraw } from "@excalidraw/excalidraw";
import { useCallback, useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

import { ApiError } from "@/lib/api";
import { ensureArabicFont } from "@/lib/whiteboard/arabic-font";
import { boards, parseScene, type BoardDetail } from "@/lib/whiteboard/api";
import {
  applyBackground,
  exportPage,
  fitToFrame,
  initialAppState,
  installTextMetrics,
  loadPage,
  restorePage,
  type BoardApi,
  type BoardElement,
} from "@/lib/whiteboard/excalidraw-api";
import { BACKGROUNDS, PAGE_HEIGHT, PAGE_WIDTH, type BoardBackground } from "@/lib/whiteboard/page-model";
import { WB } from "@/lib/whiteboard/strings";
import { BoardToolbar } from "@/components/whiteboard/BoardToolbar";

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
 * Saving to the server is story 2: this version keeps edits in memory.
 */

const REVEAL_TOP_PX = 48;

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

  // The face first, then the pages: text measured before Cairo loads is clipped.
  useEffect(() => {
    let alive = true;
    installTextMetrics();
    Promise.all([ensureArabicFont(), boards.show(boardUuid)])
      .then(([, detail]) => {
        if (!alive) return;
        pages.current = new Map(detail.pages.map((page) => [page.uuid, restorePage(parseScene(page).elements)]));
        setBackground(detail.background);
        setBoard(detail);
      })
      .catch((error: unknown) => {
        if (alive) setFailure(error instanceof ApiError && error.status === 404 ? WB.notFound : WB.loadFailed);
      });
    return () => {
      alive = false;
    };
  }, [boardUuid]);

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

  const goTo = useCallback(
    (target: number) => {
      if (!api || !board || target < 0 || target >= board.pages.length || target === pageIndex) return;
      pages.current.set(board.pages[pageIndex].uuid, api.getSceneElementsIncludingDeleted());
      loadPage(api, pages.current.get(board.pages[target].uuid) ?? []);
      setPageIndex(target);
      fit();
    },
    [api, board, pageIndex, fit],
  );

  // Next / previous page from the keyboard — never while typing.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.target instanceof Element && event.target.closest("textarea, input, select, [contenteditable]")) return;
      if (event.key === "PageDown") goTo(pageIndex + 1);
      if (event.key === "PageUp") goTo(pageIndex - 1);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [goTo, pageIndex]);

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
        .wb-board[data-presenting="true"]:not([data-reveal="true"]) .layer-ui__wrapper { visibility: hidden; }
      `}</style>
      <Excalidraw
        excalidrawAPI={setApi}
        langCode="ar-SA"
        viewModeEnabled={!board.can.edit}
        initialData={{
          elements: pages.current.get(pageIds[0]) ?? [],
          appState: initialAppState(background, window.innerWidth, window.innerHeight),
        }}
        renderTopRightUI={() => (
          <BoardToolbar
            title={board.title}
            background={background}
            canEdit={board.can.edit}
            pageIndex={pageIndex}
            pageCount={board.pages.length}
            presenting={presenting}
            onPrevious={() => goTo(pageIndex - 1)}
            onNext={() => goTo(pageIndex + 1)}
            onRename={rename}
            onBackground={changeBackground}
            onExport={exportCurrent}
            onTogglePresenting={() => setPresenting((value) => !value)}
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
    </div>
  );
}
