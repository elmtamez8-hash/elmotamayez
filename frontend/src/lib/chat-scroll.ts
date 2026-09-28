"use client";

import { useCallback, useEffect, useLayoutEffect, useRef, useState, type RefObject } from "react";

/**
 * Where a chat thread's scroll position goes when its messages change.
 *
 * ⚠️ «WAS THE READER AT THE BOTTOM?» IS ASKED BEFORE THE NEW MESSAGE IS LAID OUT,
 * NEVER AFTER. The first version measured the distance in an effect that ran
 * once the new bubble was already in the DOM, so the bubble's OWN height counted
 * against the threshold: a short line followed the reader down, and a slightly
 * taller one — a wrap, or an emoji, whose fallback font lifts the line box a few
 * pixels — was judged «scrolled away» and left below the fold. Reported from
 * production on 2026-09-28 as «a message with an emoji appears only after a
 * refresh; plain text arrives live». The position is now recorded on every
 * `scroll` event, so the answer describes where the reader WAS.
 */

/** How close to the bottom still counts as «following the conversation». */
export const NEAR_BOTTOM_PX = 120;

export type ScrollAction =
  /** Opening the thread: straight to the newest, no animation. */
  | "bottom-instant"
  /** A new message the reader should see arrive. */
  | "bottom-smooth"
  /** Older messages were added above: keep the reader on the line they were reading. */
  | "keep-offset"
  /** A new message while the reader is up in the history: tell them, don't move them. */
  | "pill"
  | "none";

/** The three facts about a list that decide the action. */
export type ListShape = { first: string | null; last: string | null; count: number };

export function shapeOf(uuids: readonly string[]): ListShape {
  return {
    first: uuids[0] ?? null,
    last: uuids[uuids.length - 1] ?? null,
    count: uuids.length,
  };
}

export function isNearBottom(
  box: { scrollHeight: number; scrollTop: number; clientHeight: number },
  threshold = NEAR_BOTTOM_PX,
): boolean {
  return box.scrollHeight - box.scrollTop - box.clientHeight <= threshold;
}

/**
 * The decision, with no DOM in it — jsdom lays nothing out, so this is the part a
 * test can actually measure.
 *
 * ⚠️ THE READER'S OWN MESSAGE ALWAYS SCROLLS. Somebody who scrolled up to quote an
 * older line and then pressed send must see what they sent; leaving them where
 * they were reads as a send that did nothing.
 */
export function decideScroll(
  previous: ListShape | null,
  next: ListShape,
  context: { wasNearBottom: boolean; lastIsMine: boolean },
): ScrollAction {
  if (next.count === 0) return "none";

  if (previous === null || previous.count === 0) return "bottom-instant";

  // The tail moved: something new at the bottom.
  if (next.last !== previous.last) {
    if (context.lastIsMine || context.wasNearBottom) return "bottom-smooth";

    return "pill";
  }

  // The head moved and the tail did not: an older page came in above.
  if (next.first !== previous.first && next.count > previous.count) return "keep-offset";

  return "none";
}

function prefersReducedMotion(): boolean {
  return typeof window !== "undefined"
    && typeof window.matchMedia === "function"
    && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/**
 * The hook a thread's scroll box uses. Returns whether the «new messages» pill
 * is showing and the action that dismisses it.
 *
 * Also keeps a reader who is at the bottom AT the bottom while a picture above
 * them finishes loading: an `<img>` arrives with no height and gains one later,
 * which would otherwise push the newest message out of view one frame after it
 * was scrolled to.
 */
export function useChatScroll(
  box: RefObject<HTMLElement | null>,
  content: RefObject<HTMLElement | null>,
  uuids: readonly string[],
  lastIsMine: boolean,
): { unseen: boolean; jumpToLatest: () => void } {
  const wasNearBottom = useRef(true);
  const previous = useRef<ListShape | null>(null);
  const previousHeight = useRef(0);
  const [unseen, setUnseen] = useState(false);

  const toBottom = useCallback(
    (smooth: boolean) => {
      const element = box.current;

      if (element === null) return;

      const top = element.scrollHeight;

      if (smooth && !prefersReducedMotion() && typeof element.scrollTo === "function") {
        element.scrollTo({ top, behavior: "smooth" });
      } else {
        element.scrollTop = top;
      }

      wasNearBottom.current = true;
    },
    [box],
  );

  // Where the reader is, recorded as they move — see the banner.
  useEffect(() => {
    const element = box.current;

    if (element === null) return;

    const onScroll = () => {
      wasNearBottom.current = isNearBottom(element);
      // The height the next older page is measured against: whatever grew since
      // the last render (a picture finishing, a window resize) is already in it.
      previousHeight.current = element.scrollHeight;

      if (wasNearBottom.current) setUnseen(false);
    };

    element.addEventListener("scroll", onScroll, { passive: true });

    return () => element.removeEventListener("scroll", onScroll);
  }, [box]);

  const key = uuids.join("|");

  // Layout, not a plain effect: the position is corrected before the frame is
  // painted, so a prepended page never flashes at the wrong offset.
  useLayoutEffect(() => {
    const element = box.current;
    const next = shapeOf(uuids);
    const action = decideScroll(previous.current, next, {
      wasNearBottom: wasNearBottom.current,
      lastIsMine,
    });

    if (element !== null) {
      if (action === "bottom-instant") toBottom(false);
      if (action === "bottom-smooth") toBottom(true);
      if (action === "keep-offset") {
        element.scrollTop += element.scrollHeight - previousHeight.current;
      }
      if (action === "pill") setUnseen(true);
      if (action === "bottom-instant" || action === "bottom-smooth") setUnseen(false);

      previousHeight.current = element.scrollHeight;
    }

    previous.current = next;
    // `key` stands for the list; the array itself is a new object every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, lastIsMine, toBottom, box]);

  // A late-loading picture grows the content: stay pinned if the reader was.
  useEffect(() => {
    const element = content.current;

    if (element === null || typeof ResizeObserver === "undefined") return;

    const observer = new ResizeObserver(() => {
      if (wasNearBottom.current) toBottom(false);

      if (box.current !== null) previousHeight.current = box.current.scrollHeight;
    });

    observer.observe(element);

    return () => observer.disconnect();
  }, [content, box, toBottom]);

  return {
    unseen,
    jumpToLatest: () => {
      toBottom(true);
      setUnseen(false);
    },
  };
}
