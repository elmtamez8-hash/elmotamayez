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
 *
 * ⚠️ AND A SCROLL EVENT IS NOT ALWAYS THE READER. The browser dispatches the
 * event for OUR OWN scroll a frame later, and reads the layout as it is THEN —
 * after a picture that finished loading in between has already grown the list.
 * So the event for «we just scrolled to the bottom» reported the reader 200px
 * above it, the hook concluded they had scrolled away, and the ResizeObserver
 * that exists for exactly that picture declined to follow (live two-account test,
 * 2026-09-28: `scrollHeight - clientHeight - scrollTop` = 200 on both sides after
 * an image arrived). Measured in Chromium: the observer fired, but only after the
 * late scroll event had already cleared the flag. Content growth never moves
 * `scrollTop`, and every scroll this hook makes moves it DOWN — so only an event
 * whose `scrollTop` did not go down may unpin the reader; see `nextPinned()`.
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
 * Whether the reader is still following the conversation after a scroll event.
 *
 * Near the bottom always pins. Away from it unpins ONLY when `scrollTop` did not
 * go down since the last event — the reader moved up (or not at all). A downward
 * move that is still far from the bottom is one of our own scrolls reported late
 * (see the banner) or a smooth scroll still on its way, and it keeps whatever
 * the reader was.
 */
export function nextPinned(
  pinned: boolean,
  previousTop: number,
  box: { scrollHeight: number; scrollTop: number; clientHeight: number },
): boolean {
  if (isNearBottom(box)) return true;

  if (box.scrollTop <= previousTop) return false;

  return pinned;
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

  // Something was taken away (a message hidden, possibly the newest). Nothing
  // arrived, so there is nothing to follow and nothing to announce.
  if (next.count < previous.count) return "none";

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
  // The `scrollTop` of the last scroll event. Infinity so the very first event
  // is judged on its position alone.
  const lastTop = useRef(Number.POSITIVE_INFINITY);
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
      wasNearBottom.current = nextPinned(wasNearBottom.current, lastTop.current, element);
      lastTop.current = element.scrollTop;
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

  /*
   * A late-loading picture grows the content: stay pinned if the reader was.
   * `wasNearBottom` here is the flag `nextPinned()` keeps, so our own late-reported
   * scroll events cannot clear it before this runs — see the banner.
   *
   * ⚠️ RE-RUN WHEN THE LIST FIRST APPEARS. The `<ul>` is rendered only once
   * there is a message, and a room's chat (and an empty private thread) mounts
   * with none — an effect keyed on the refs alone ran once, found nothing to
   * observe, and never ran again.
   */
  const hasContent = uuids.length > 0;

  useEffect(() => {
    const element = content.current;

    if (element === null || typeof ResizeObserver === "undefined") return;

    // Instant, never smooth: an instant scroll also cancels a smooth one still
    // running toward the old, shorter bottom.
    const observer = new ResizeObserver(() => {
      if (wasNearBottom.current) toBottom(false);

      if (box.current !== null) previousHeight.current = box.current.scrollHeight;
    });

    observer.observe(element);
    // The box too: a composer that grows (a picture's preview, an error line)
    // shrinks the box, and the newest message would slide under it.
    if (box.current !== null) observer.observe(box.current);

    return () => observer.disconnect();
  }, [content, box, toBottom, hasContent]);

  return {
    unseen,
    jumpToLatest: () => {
      toBottom(true);
      setUnseen(false);
    },
  };
}
