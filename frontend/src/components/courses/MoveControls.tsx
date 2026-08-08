"use client";

import { ChevronDownIcon, ChevronUpIcon } from "@/components/icons";

/**
 * Move an item one step among its siblings.
 *
 * Buttons, not drag-and-drop, and that is a decision rather than a shortcut.
 * The frontend carries four dependencies and none of them is a drag library;
 * native HTML5 dragging is hostile to screen readers and fragile under RTL
 * coordinates; and a keyboard path has to be built either way, so it is built
 * first. Dragging can sit on top of the same endpoint later.
 *
 * Up and down, not start and end: this axis is vertical, so it does not flip
 * with the writing direction.
 */
export function MoveControls({
  label,
  canMoveUp,
  canMoveDown,
  onMove,
  busy = false,
}: {
  /** Named in the button's accessible label — "move up" alone is ambiguous in a tree. */
  label: string;
  canMoveUp: boolean;
  canMoveDown: boolean;
  onMove: (direction: -1 | 1) => void;
  busy?: boolean;
}) {
  return (
    <span className="inline-flex items-center gap-1">
      <button
        type="button"
        onClick={() => onMove(-1)}
        disabled={!canMoveUp || busy}
        aria-label={`تحريك «${label}» لأعلى`}
        className="rounded p-1 text-ink-muted transition hover:bg-surface-raised hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
      >
        <ChevronUpIcon className="size-4" />
      </button>
      <button
        type="button"
        onClick={() => onMove(1)}
        disabled={!canMoveDown || busy}
        aria-label={`تحريك «${label}» لأسفل`}
        className="rounded p-1 text-ink-muted transition hover:bg-surface-raised hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
      >
        <ChevronDownIcon className="size-4" />
      </button>
    </span>
  );
}
