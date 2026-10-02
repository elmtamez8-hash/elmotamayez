"use client";

import { useEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { WB } from "@/lib/whiteboard/strings";

export interface SidebarPage {
  uuid: string;
  /** Changes whenever the page's drawing does — the thumbnail cache key. */
  version: number;
}

export interface PagesSidebarProps {
  pages: SidebarPage[];
  current: number;
  canEdit: boolean;
  busy: boolean;
  /** Draw a page's small picture (a data URL). Asked only for a page that is on screen. */
  thumbnail: (uuid: string) => Promise<string>;
  onSelect: (index: number) => void;
  onAdd: (afterUuid: string) => void;
  onDuplicate: (uuid: string) => void;
  onDelete: (uuid: string) => void;
  onReorder: (uuids: string[]) => void;
}

/** A page's picture is redrawn this long after its last change. */
const REFRESH_AFTER_MS = 3000;

/** Run when the browser is idle (Safari has no requestIdleCallback). */
function whenIdle(fn: () => void): () => void {
  if (typeof window.requestIdleCallback === "function") {
    const id = window.requestIdleCallback(fn, { timeout: 1000 });
    return () => window.cancelIdleCallback(id);
  }
  const id = setTimeout(fn, 50);
  return () => clearTimeout(id);
}

function Thumb({ page, index, thumbnail }: { page: SidebarPage; index: number; thumbnail: (uuid: string) => Promise<string> }) {
  const ref = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(false);
  const [src, setSrc] = useState<{ key: string; url: string } | null>(null);
  const key = `${page.uuid}:${page.version}`;

  useEffect(() => {
    const node = ref.current;
    if (!node) return;
    const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting));
    observer.observe(node);
    return () => observer.disconnect();
  }, []);

  // Only a page on screen is drawn, once per version, when the browser is idle —
  // and a picture already there waits for the drawing to pause: every stroke is a
  // new version, and redrawing the whole page after each one competed with the pen.
  const hasPicture = src !== null;
  useEffect(() => {
    if (!visible || src?.key === key) return;
    let alive = true;
    let cancel = () => {};
    const wait = setTimeout(
      () => {
        cancel = whenIdle(() => {
          thumbnail(page.uuid)
            .then((url) => alive && setSrc({ key, url }))
            // The number stays in place of the picture; the reason goes to the console.
            .catch((error: unknown) => console.warn("[whiteboard] thumbnail", error));
        });
      },
      hasPicture ? REFRESH_AFTER_MS : 0,
    );
    return () => {
      alive = false;
      clearTimeout(wait);
      cancel();
    };
  }, [visible, key, src?.key, hasPicture, page.uuid, thumbnail]);

  return (
    <div ref={ref} className="aspect-video w-full overflow-hidden rounded-md border border-line bg-surface" data-thumb={page.uuid}>
      {src ? (
        // A data URL drawn in this browser — nothing to optimise through next/image.
        // eslint-disable-next-line @next/next/no-img-element
        <img src={src.url} alt={WB.pageNumber(index + 1)} className="h-full w-full object-contain" />
      ) : (
        <span className="flex h-full items-center justify-center text-xs text-ink-muted">{WB.pageNumber(index + 1)}</span>
      )}
    </div>
  );
}

/**
 * The board's pages (US3): pick, add, copy, delete, and reorder by dragging (or
 * with the move buttons — dragging alone cannot be done from a keyboard).
 * Pure: every change is the canvas's, so it is tested by pressing it.
 */
export function PagesSidebar(props: PagesSidebarProps) {
  const { pages, current, canEdit, busy } = props;
  const [dragged, setDragged] = useState<number | null>(null);

  const move = (from: number, to: number) => {
    if (from === to || to < 0 || to >= pages.length) return;
    const order = pages.map((page) => page.uuid);
    const [taken] = order.splice(from, 1);
    order.splice(to, 0, taken);
    props.onReorder(order);
  };

  return (
    <nav aria-label={WB.pagesTitle} className="flex h-full w-48 flex-col gap-2 overflow-y-auto border-e border-line bg-surface-raised p-2" dir="rtl">
      <ol className="flex flex-col gap-2">
        {pages.map((page, index) => (
          <li
            key={page.uuid}
            draggable={canEdit && !busy}
            onDragStart={() => setDragged(index)}
            onDragOver={(event) => event.preventDefault()}
            onDrop={() => {
              if (dragged !== null) move(dragged, index);
              setDragged(null);
            }}
            className={`flex flex-col gap-1 rounded-lg p-1 ${index === current ? "bg-primary-soft" : ""}`}
          >
            <button type="button" onClick={() => props.onSelect(index)} aria-current={index === current ? "page" : undefined} className="text-start">
              <Thumb page={page} index={index} thumbnail={props.thumbnail} />
            </button>
            {canEdit && (
              <div className="flex flex-wrap items-center gap-1">
                <Button size="sm" variant="ghost" disabled={busy || index === 0} onClick={() => move(index, index - 1)}>
                  {/* `Button` takes no aria-label, so the name is text a screen reader reads. */}
                  <span aria-hidden>↑</span>
                  <span className="sr-only">{WB.moveUp}</span>
                </Button>
                <Button size="sm" variant="ghost" disabled={busy || index === pages.length - 1} onClick={() => move(index, index + 1)}>
                  <span aria-hidden>↓</span>
                  <span className="sr-only">{WB.moveDown}</span>
                </Button>
                <Button size="sm" variant="ghost" disabled={busy} onClick={() => props.onDuplicate(page.uuid)}>
                  {WB.duplicatePage}
                </Button>
                {pages.length > 1 && (
                  <ConfirmButton size="sm" variant="ghost" disabled={busy} confirmLabel={WB.confirmDeletePage} onConfirm={() => props.onDelete(page.uuid)}>
                    {WB.deletePage}
                  </ConfirmButton>
                )}
              </div>
            )}
          </li>
        ))}
      </ol>
      {canEdit && (
        <Button size="sm" variant="secondary" disabled={busy} onClick={() => props.onAdd(pages[current]?.uuid ?? pages[pages.length - 1].uuid)}>
          {WB.addPage}
        </Button>
      )}
    </nav>
  );
}
