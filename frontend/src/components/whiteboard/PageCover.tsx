"use client";

import { useEffect, useState } from "react";
import { createPortal } from "react-dom";

import { pageScreens, type BoardApi } from "@/lib/whiteboard/excalidraw-api";
import { BACKGROUNDS, PAGE_HEIGHT, PAGE_WIDTH, type BoardBackground } from "@/lib/whiteboard/page-model";
import { templateUrl, type TemplateName } from "@/lib/whiteboard/templates";

type Rect = { left: number; top: number; width: number; height: number };

/**
 * Everything outside the page, painted over: STUDENTS SEE THIS TAB LIVE. Its own
 * component, so a scroll or a zoom re-renders this cover and not the whole board
 * (and with it Excalidraw's whole UI layer) — it subscribes to the canvas itself.
 */
export function PageCover({ api, background, template }: { api: BoardApi; background: BoardBackground; template: TemplateName | null }) {
  const [rect, setRect] = useState<Rect>({ left: 0, top: 0, width: 0, height: 0 });
  const [root, setRoot] = useState<Element | null>(null);
  const [image, setImage] = useState<string | null>(null);

  useEffect(() => {
    const sync = () => {
      const { scrollX, scrollY, zoom } = api.getAppState();
      const next = {
        left: scrollX * zoom.value,
        top: scrollY * zoom.value,
        width: PAGE_WIDTH * zoom.value,
        // The page's REAL height: it grows downward in screens.
        height: pageScreens(api) * PAGE_HEIGHT * zoom.value,
      };
      setRect((r) => (r.left === next.left && r.top === next.top && r.width === next.width && r.height === next.height ? r : next));
    };
    sync();
    // A scroll, and a scene change: switching to a page of another height scrolls
    // to the same spot, and Excalidraw sends no scroll event for that.
    const offScroll = api.onScrollChange(sync);
    const offChange = api.onChange((_, appState) => {
      if (appState.cursorButton !== "down") sync(); // a stroke never changes the frame
    });
    return () => {
      offScroll();
      offChange();
    };
  }, [api]);

  // Excalidraw's own root is the containing block the scroll numbers are in; it
  // exists once the api does (no frame wait — a background tab runs no frames).
  useEffect(() => setRoot(document.querySelector(".wb-board .excalidraw")), [api]);

  useEffect(() => {
    if (!template) return setImage(null);
    let alive = true;
    void templateUrl(template).then((url) => alive && setImage(url));
    return () => {
      alive = false;
    };
  }, [template]);

  if (!root) return null;
  const { left, top, width, height } = rect;
  return createPortal(
    <div
      aria-hidden
      className="pointer-events-none absolute inset-0"
      style={{
        zIndex: 3,
        // Everything but the page: a hole cut in a full cover (even-odd).
        clipPath: `polygon(evenodd, 0 0, 100% 0, 100% 100%, 0 100%, 0 0, ${left}px ${top}px, ${left + width}px ${top}px, ${left + width}px ${top + height}px, ${left}px ${top + height}px, ${left}px ${top}px)`,
        backgroundColor: BACKGROUNDS[background].canvas,
        // The template carries on past the page's edges, in step with it (owner, 2026-10-02).
        ...(image && {
          backgroundImage: `url(${image})`,
          backgroundSize: `${width}px ${(width * PAGE_HEIGHT) / PAGE_WIDTH}px`,
          backgroundPosition: `${left}px ${top}px`,
        }),
      }}
    />,
    root,
  );
}
