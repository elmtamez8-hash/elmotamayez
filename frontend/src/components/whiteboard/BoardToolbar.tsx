"use client";

import { useState, type ReactNode } from "react";

import {
  ArrowDownIcon,
  ArrowUpIcon,
  BookIcon,
  ChevronDownIcon,
  ChevronEndIcon,
  ChevronStartIcon,
  ChevronUpIcon,
  ConfettiIcon,
  ImportIcon,
  PanelLayoutIcon,
  PlusIcon,
  PresentIcon,
  ToolsIcon,
} from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { BoardSettings } from "@/components/whiteboard/BoardSettings";
import { ExportMenu } from "@/components/whiteboard/ExportMenu";
import type { ExportKind } from "@/lib/whiteboard/excalidraw-api";
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
  onExport: (kind: ExportKind) => Promise<void>;
  onTogglePresenting: () => void;
  /** The screen of the page shown, and how many it has (a page grows downward). */
  screen?: { index: number; count: number };
  onScreen?: (delta: number) => void;
  /**
   * The tool groups (teaching tools, presenting, encouragement), each behind one
   * tile so the panel stays small over the board. One open at a time.
   */
  menus?: ToolMenu[];
  /** Who edits and whether the work is saved (story 2). */
  status?: ReactNode;
}

export interface ToolMenu {
  id: string;
  label: string;
  content: ReactNode;
}

/** Each tool group's picture; a group without one shows its name alone. */
const MENU_ICONS: Record<string, ReactNode> = {
  tools: <ToolsIcon className="h-5 w-5" />,
  present: <PresentIcon className="h-5 w-5" />,
  encourage: <ConfettiIcon className="h-5 w-5" />,
  layout: <PanelLayoutIcon className="h-5 w-5" />,
  import: <ImportIcon className="h-5 w-5" />,
  lesson: <BookIcon className="h-5 w-5" />,
};

const OPEN_KEY = "whiteboard.panel.open";

/** Whether the teacher left the panel open — a per-browser convenience, so storage may fail. */
function remembered(): boolean {
  try {
    return localStorage.getItem(OPEN_KEY) === "1";
  } catch {
    return false;
  }
}

/** A square control whose picture is its whole face; the name is read aloud and shown on hover. */
function IconButton({ label, onClick, disabled, expanded, controls, children }: { label: string; onClick: () => void; disabled?: boolean; expanded?: boolean; controls?: string; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      aria-expanded={expanded}
      aria-controls={controls}
      disabled={disabled}
      onClick={onClick}
      className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink transition duration-200 ease-out hover:bg-surface active:scale-[0.94] disabled:cursor-not-allowed disabled:opacity-35 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-expanded:bg-surface aria-expanded:text-primary-ink"
    >
      {children}
    </button>
  );
}

/**
 * The board's own controls, drawn into Excalidraw's top bar (`renderTopRightUI`).
 *
 * FOLDED by default (owner, 2026-10-03): one slim bar with what a lesson needs
 * every minute — the pages, the page's screens, «عرض» and whether the work is
 * saved — and the rest (name and background, export, the tool groups) one press away, so the
 * panel covers as little of the board as it can. Pure — every action is the
 * canvas's — so it is tested by pressing it.
 */
export function BoardToolbar(props: BoardToolbarProps) {
  const { pageIndex, pageCount } = props;
  const [expanded, setExpanded] = useState(remembered);
  const [open, setOpen] = useState<string | null>(null);
  const shown = props.menus?.find((menu) => menu.id === open);

  const toggle = () =>
    setExpanded((now) => {
      try {
        localStorage.setItem(OPEN_KEY, now ? "0" : "1");
      } catch {
        // ponytail: a private window just forgets it.
      }
      return !now;
    });

  return (
    // Its own light panel: the toolbar sits ON the canvas, and dark ink on the
    // blackboard or the green board is unreadable (seen in the browser).
    <div
      data-panel="board"
      data-expanded={expanded ? "true" : "false"}
      className={`flex max-w-[60vw] flex-col rounded-2xl border border-line bg-surface-raised text-ink shadow-[0_6px_24px_-8px_rgb(0_0_0/0.25)] ${expanded ? "w-[31rem]" : "w-fit"}`}
      dir="rtl"
    >
      <div className="flex items-center gap-1 px-2 py-1.5">
        <IconButton label={expanded ? WB.panel.fold : WB.panel.unfold} expanded={expanded} controls="wb-panel-body" onClick={toggle}>
          {expanded ? <ChevronUpIcon className="h-4 w-4" /> : <ChevronDownIcon className="h-4 w-4" />}
        </IconButton>
        {/* Open, the name is shown whole in the panel itself: cut short here it read «مراجعة ا…». */}
        {!expanded && (
          <span className="hidden min-w-0 max-w-[10rem] truncate px-1 text-sm font-bold lg:inline" title={props.title}>
            {props.title}
          </span>
        )}

        <div className="ms-auto flex items-center gap-0.5 rounded-xl bg-surface px-0.5" role="group" aria-label={WB.panel.pages}>
          <IconButton label={WB.previousPage} disabled={pageIndex === 0} onClick={props.onPrevious}>
            <ChevronStartIcon />
          </IconButton>
          {/* Named: beside it is a second «١ من ١», the screens, and the two read alike. */}
          <span className="min-w-[3.5rem] whitespace-nowrap text-center text-sm font-semibold tabular-nums" aria-live="polite">
            <span className="me-1 text-xs font-normal text-ink-muted">{WB.pageWord}</span>
            {WB.pageOf(pageIndex + 1, pageCount)}
          </span>
          <IconButton label={WB.nextPage} disabled={pageIndex >= pageCount - 1} onClick={props.onNext}>
            <ChevronEndIcon />
          </IconButton>
        </div>

        {/* The screens of the page, in the bar too: moved through all lesson long (owner, 2026-10-03). */}
        {props.screen && props.onScreen && (
          <div className="flex items-center gap-0.5 rounded-xl bg-surface px-0.5" role="group" aria-label={WB.screens}>
            <IconButton label={WB.screenUp} disabled={props.screen.index === 0} onClick={() => props.onScreen?.(-1)}>
              <ArrowUpIcon className="h-4 w-4" />
            </IconButton>
            <span className="min-w-[3rem] whitespace-nowrap text-center text-xs font-semibold tabular-nums text-ink-muted" aria-hidden="true">
              <span className="me-1 font-normal">{WB.screenWord}</span>
              {WB.pageOf(props.screen.index + 1, props.screen.count)}
            </span>
            <span className="sr-only" aria-live="polite">
              {WB.screenOf(props.screen.index + 1, props.screen.count)}
            </span>
            <IconButton
              label={props.screen.index >= props.screen.count - 1 ? WB.screenNew : WB.screenDown}
              disabled={!props.canEdit && props.screen.index >= props.screen.count - 1}
              onClick={() => props.onScreen?.(1)}
            >
              {/* The last screen grows the page: a «+», not the arrow it shares with «أسفل». */}
              {props.screen.index >= props.screen.count - 1 ? <PlusIcon /> : <ArrowDownIcon className="h-4 w-4" />}
            </IconButton>
          </div>
        )}
        <Button size="sm" variant={props.presenting ? "secondary" : "primary"} iconStart={<PresentIcon />} onClick={props.onTogglePresenting}>
          {props.presenting ? WB.stopPresenting : WB.present}
        </Button>
      </div>

      {expanded && (
        <div id="wb-panel-body" className="flex flex-col gap-3 border-t border-line px-3 pb-3 pt-2.5">
          <BoardSettings title={props.title} background={props.background} disabled={!props.canEdit} onRename={props.onRename} onBackground={props.onBackground} />
          <ExportMenu onExport={props.onExport} />

          {props.menus && props.menus.length > 0 && (
            <div className="grid grid-cols-3 gap-1.5 border-t border-line pt-3">
              {props.menus.map((menu) => {
                const active = open === menu.id;
                return (
                  <button
                    key={menu.id}
                    type="button"
                    aria-expanded={active}
                    aria-controls={`wb-menu-${menu.id}`}
                    onClick={() => setOpen((current) => (current === menu.id ? null : menu.id))}
                    className={`flex min-h-[3.5rem] flex-col items-center justify-center gap-1 rounded-xl border px-1 py-1.5 text-xs font-semibold transition duration-200 ease-out active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                      active ? "border-primary bg-surface text-primary-ink" : "border-transparent text-ink hover:bg-surface"
                    }`}
                  >
                    {MENU_ICONS[menu.id]}
                    <span className="leading-tight">{menu.label}</span>
                  </button>
                );
              })}
            </div>
          )}
          {shown && (
            <div id={`wb-menu-${shown.id}`} className="rounded-xl bg-surface p-3">
              {shown.content}
            </div>
          )}
        </div>
      )}

      {props.status && <div className="flex flex-col gap-1 border-t border-line px-3 py-1.5">{props.status}</div>}
    </div>
  );
}
