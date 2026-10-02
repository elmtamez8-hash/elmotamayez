"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { BoardSettings } from "@/components/whiteboard/BoardSettings";
import { ExportMenu } from "@/components/whiteboard/ExportMenu";
import type { BoardBackground } from "@/lib/whiteboard/page-model";
import { WB } from "@/lib/whiteboard/strings";

export interface BoardToolbarProps {
  title: string;
  background: BoardBackground;
  canEdit: boolean;
  pageIndex: number;
  pageCount: number;
  presenting: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onRename: (title: string) => Promise<void>;
  onBackground: (background: BoardBackground) => void;
  onExport: (kind: "png" | "svg") => Promise<void>;
  onTogglePresenting: () => void;
  /** The screen of the page shown, and how many it has (a page grows downward). */
  screen?: { index: number; count: number };
  onScreen?: (delta: number) => void;
  pagesOpen?: boolean;
  onTogglePages?: () => void;
  /**
   * The tool groups (teaching tools, presenting, encouragement), each behind one
   * button so the bar stays small over the board. One open at a time.
   */
  menus?: ToolMenu[];
  /** Who edits and whether the work is saved (story 2). */
  status?: React.ReactNode;
}

/**
 * The board's own controls, drawn into Excalidraw's top bar (`renderTopRightUI`):
 * pages, name and background, export, and «عرض». Pure — every action is the
 * canvas's — so it is tested by pressing it.
 */
export interface ToolMenu {
  id: string;
  label: string;
  content: React.ReactNode;
}

export function BoardToolbar(props: BoardToolbarProps) {
  const { pageIndex, pageCount } = props;
  const [open, setOpen] = useState<string | null>(null);
  const shown = props.menus?.find((menu) => menu.id === open);

  return (
    // Its own light panel: the toolbar sits ON the canvas, and dark ink on the
    // blackboard or the green board is unreadable (seen in the browser). Two rows,
    // so the rename field never squeezes the background picker to nothing.
    <div className="flex w-[26rem] max-w-[60vw] flex-col gap-1.5 rounded-xl border border-line bg-surface-raised px-3 py-2 text-ink shadow-sm" dir="rtl">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-1">
          <Button size="sm" variant="ghost" disabled={pageIndex === 0} onClick={props.onPrevious}>
            {WB.previousPage}
          </Button>
          <span className="text-sm tabular-nums" aria-live="polite">
            {WB.pageOf(pageIndex + 1, pageCount)}
          </span>
          <Button size="sm" variant="ghost" disabled={pageIndex >= pageCount - 1} onClick={props.onNext}>
            {WB.nextPage}
          </Button>
        </div>
        <div className="flex items-center gap-1">
          {props.onTogglePages && (
            <Button size="sm" variant="ghost" expanded={props.pagesOpen} onClick={props.onTogglePages}>
              {WB.showPages}
            </Button>
          )}
          <Button size="sm" variant={props.presenting ? "secondary" : "primary"} onClick={props.onTogglePresenting}>
            {props.presenting ? WB.stopPresenting : WB.present}
          </Button>
        </div>
      </div>
      {props.screen && props.onScreen && (
        <div className="flex items-center gap-1">
          <Button size="sm" variant="ghost" disabled={props.screen.index === 0} onClick={() => props.onScreen?.(-1)}>
            {WB.screenUp}
          </Button>
          <span className="text-sm tabular-nums" aria-live="polite">
            {WB.screenOf(props.screen.index + 1, props.screen.count)}
          </span>
          <Button size="sm" variant="secondary" onClick={() => props.onScreen?.(1)}>
            {props.screen.index >= props.screen.count - 1 ? WB.screenNew : WB.screenDown}
          </Button>
        </div>
      )}
      <BoardSettings
        title={props.title}
        background={props.background}
        disabled={!props.canEdit}
        onRename={props.onRename}
        onBackground={props.onBackground}
      />
      <ExportMenu onExport={props.onExport} />
      {props.menus && props.menus.length > 0 && (
        <div className="flex flex-wrap items-center gap-1 border-t border-line pt-1.5">
          {props.menus.map((menu) => (
            <Button
              key={menu.id}
              size="sm"
              variant={open === menu.id ? "secondary" : "ghost"}
              expanded={open === menu.id}
              controls={`wb-menu-${menu.id}`}
              onClick={() => setOpen((current) => (current === menu.id ? null : menu.id))}
            >
              {menu.label} {open === menu.id ? "▴" : "▾"}
            </Button>
          ))}
        </div>
      )}
      {shown && <div id={`wb-menu-${shown.id}`}>{shown.content}</div>}
      {props.status && <div className="flex flex-col gap-1 border-t border-line pt-1.5">{props.status}</div>}
    </div>
  );
}
