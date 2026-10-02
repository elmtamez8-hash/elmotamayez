"use client";

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
  pagesOpen?: boolean;
  onTogglePages?: () => void;
  /** Who edits and whether the work is saved (story 2). */
  status?: React.ReactNode;
}

/**
 * The board's own controls, drawn into Excalidraw's top bar (`renderTopRightUI`):
 * pages, name and background, export, and «عرض». Pure — every action is the
 * canvas's — so it is tested by pressing it.
 */
export function BoardToolbar(props: BoardToolbarProps) {
  const { pageIndex, pageCount } = props;

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
      <BoardSettings
        title={props.title}
        background={props.background}
        disabled={!props.canEdit}
        onRename={props.onRename}
        onBackground={props.onBackground}
      />
      <ExportMenu onExport={props.onExport} />
      {props.status && <div className="flex flex-col gap-1 border-t border-line pt-1.5">{props.status}</div>}
    </div>
  );
}
