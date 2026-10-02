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
}

/**
 * The board's own controls, drawn into Excalidraw's top bar (`renderTopRightUI`):
 * pages, name and background, export, and «عرض». Pure — every action is the
 * canvas's — so it is tested by pressing it.
 */
export function BoardToolbar(props: BoardToolbarProps) {
  const { pageIndex, pageCount } = props;

  return (
    <div className="flex flex-wrap items-center gap-2" dir="rtl">
      <Button size="sm" variant="ghost" disabled={pageIndex === 0} onClick={props.onPrevious}>
        {WB.previousPage}
      </Button>
      <span className="text-sm tabular-nums" aria-live="polite">
        {WB.pageOf(pageIndex + 1, pageCount)}
      </span>
      <Button size="sm" variant="ghost" disabled={pageIndex >= pageCount - 1} onClick={props.onNext}>
        {WB.nextPage}
      </Button>
      <BoardSettings
        title={props.title}
        background={props.background}
        disabled={!props.canEdit}
        onRename={props.onRename}
        onBackground={props.onBackground}
      />
      <ExportMenu onExport={props.onExport} />
      <Button size="sm" variant={props.presenting ? "secondary" : "primary"} onClick={props.onTogglePresenting}>
        {props.presenting ? WB.stopPresenting : WB.present}
      </Button>
    </div>
  );
}
