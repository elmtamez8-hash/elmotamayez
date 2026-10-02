"use client";

import { useEffect, useState } from "react";
import { createPortal } from "react-dom";

import { pageScreens, type BoardApi } from "@/lib/whiteboard/excalidraw-api";
import { BACKGROUNDS, PAGE_HEIGHT, PAGE_WIDTH, type BoardBackground } from "@/lib/whiteboard/page-model";
import { templateUrl, type TemplateName } from "@/lib/whiteboard/templates";

/**
 * The board's colour and template, and the cover over everything outside the page
 * (STUDENTS SEE THIS TAB LIVE).
 *
 * The canvas is transparent: the colour and the template are ONE layer — the
 * background of Excalidraw's own root — tiled in step with the page, inside it
 * and past its edges alike. The cover above the canvas carries the same
 * background with the page cut out (even-odd clip-path), hiding anything drawn
 * outside the page. A scroll or a zoom only sets four CSS variables on the root:
 * no React render at all.
 */
export function PageCover({ api, background, template }: { api: BoardApi; background: BoardBackground; template: TemplateName | null }) {
  const [root, setRoot] = useState<HTMLElement | null>(null);
  const [image, setImage] = useState<string | null>(null);

  // Excalidraw's own root is the containing block the scroll numbers are in; it
  // exists once the api does (no frame wait — a background tab runs no frames).
  useEffect(() => setRoot(document.querySelector<HTMLElement>(".wb-board .excalidraw")), [api]);

  useEffect(() => {
    if (!root) return;
    const sync = () => {
      const { scrollX, scrollY, zoom } = api.getAppState();
      root.style.setProperty("--wb-x", `${scrollX * zoom.value}px`);
      root.style.setProperty("--wb-y", `${scrollY * zoom.value}px`);
      root.style.setProperty("--wb-w", `${PAGE_WIDTH * zoom.value}px`);
      // The page's REAL height: it grows downward in screens.
      root.style.setProperty("--wb-h", `${pageScreens(api) * PAGE_HEIGHT * zoom.value}px`);
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
  }, [api, root]);

  useEffect(() => {
    if (!template) return setImage(null);
    let alive = true;
    void templateUrl(template).then((url) => alive && setImage(url));
    return () => {
      alive = false;
    };
  }, [template]);

  if (!root) return null;
  const x = "var(--wb-x, 0px)";
  const y = "var(--wb-y, 0px)";
  const right = "calc(var(--wb-x, 0px) + var(--wb-w, 0px))";
  const bottom = "calc(var(--wb-y, 0px) + var(--wb-h, 0px))";
  return (
    <>
      <style>{`
        .wb-board .excalidraw {
          background-color: ${BACKGROUNDS[background].canvas};
          background-image: ${image ? `url(${image})` : "none"};
          background-size: var(--wb-w, 0px) calc(var(--wb-w, 0px) * ${PAGE_HEIGHT / PAGE_WIDTH});
          background-position: ${x} ${y};
        }
      `}</style>
      {createPortal(
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0"
          style={{
            zIndex: 3,
            background: "inherit",
            clipPath: `polygon(evenodd, 0 0, 100% 0, 100% 100%, 0 100%, 0 0, ${x} ${y}, ${right} ${y}, ${right} ${bottom}, ${x} ${bottom}, ${x} ${y})`,
          }}
        />,
        root,
      )}
    </>
  );
}
