"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { ApiError } from "@/lib/api";
import { createAutosave, type Autosave, type SaveState } from "@/lib/whiteboard/autosave";
import { boards, type BoardDetail } from "@/lib/whiteboard/api";
import { classifyDraft, draftKey, indexedDbDrafts, type PageDraft } from "@/lib/whiteboard/draft-store";
import type { BoardElement } from "@/lib/whiteboard/excalidraw-api";

/**
 * The edit lock and the autosave of one open board (spec 039 · US2, R-08/R-09).
 *
 * The canvas hands in how to read and replace a page's elements; this hook owns
 * the heartbeat, the release on close, the drafts and the two questions
 * (restore, conflict). Everything it decides is in `autosave.ts`, tested there.
 */

export const HEARTBEAT_MS = 5000;

/**
 * One id per open tab. ⚠️ In `sessionStorage`, so a reload (F5) keeps it and the
 * tab keeps its own lock instead of waiting two minutes for it to expire.
 * ponytail: Chrome COPIES sessionStorage on «duplicate tab», so a duplicate starts
 * with the same id — both tabs then hold one lock and their saves conflict (never
 * lost: the second gets `version_conflict`). Upgrade path: a BroadcastChannel
 * handshake that re-rolls the id when another tab answers with it.
 */
function tabId(): string {
  const KEY = "whiteboard.tab";
  try {
    const found = sessionStorage.getItem(KEY);
    if (found) return found;
    const fresh = crypto.randomUUID();
    sessionStorage.setItem(KEY, fresh);
    return fresh;
  } catch {
    return crypto.randomUUID();
  }
}

export interface PageAccess {
  /** The elements on screen for a page, or as last kept for a page not shown. */
  read(page: string): readonly BoardElement[];
  /** Replace a page's elements (and the screen, if it is the one shown). */
  replace(page: string, scene: string): readonly BoardElement[];
  hash(elements: readonly BoardElement[]): number;
  document(elements: readonly BoardElement[]): string;
  /** The board was fetched again (a read-only tab became the editor). */
  reload(board: BoardDetail): void;
}

export interface RestoreQuestion {
  page: string;
  verdict: "restore" | "ask";
  draft: PageDraft;
}

export function useBoardSession(board: BoardDetail | null, userUuid: string | null, pages: PageAccess) {
  const [held, setHeld] = useState(false);
  const [heldBy, setHeldBy] = useState<string | null>(null);
  const [taking, setTaking] = useState(false);
  const [handoverRequested, setHandoverRequested] = useState(false);
  const [saveState, setSaveState] = useState<SaveState>("saved");
  const [refusal, setRefusal] = useState<string | null>(null);
  const [conflict, setConflict] = useState<{ page: string; version: number; scene: string } | null>(null);
  const [restores, setRestores] = useState<RestoreQuestion[]>([]);

  const save = useRef<Autosave | null>(null);
  const tab = useRef<string>("");
  const heldRef = useRef(false);
  const everHeld = useRef(false);
  const pagesRef = useRef(pages);
  pagesRef.current = pages;

  const boardUuid = board?.uuid ?? null;

  /** Give the autosave every page as the server holds it now. */
  const trackAll = (fresh: BoardDetail) => {
    const p = pagesRef.current;
    for (const page of fresh.pages) save.current?.track(page.uuid, page.version, p.hash(p.read(page.uuid)));
  };

  /** A draft left by a crash, a lost network or a lost lock, page by page (R-08). */
  const offerDrafts = (fresh: BoardDetail) => {
    if (!userUuid) return;
    void Promise.all(
      fresh.pages.map(async (page) => {
        const key = draftKey(fresh.uuid, page.uuid, userUuid);
        const draft = await indexedDbDrafts.get(key);
        if (draft === "unavailable" || draft === null) return null;
        // A draft identical to what the server holds has nothing to restore.
        const verdict = draft.scene === page.scene ? "discard" : classifyDraft(draft, page.version);
        if (verdict === "discard") {
          void indexedDbDrafts.remove(key);
          return null;
        }
        return { page: page.uuid, verdict, draft } satisfies RestoreQuestion;
      }),
    ).then((found) => setRestores(found.filter((q): q is RestoreQuestion => q !== null)));
  };
  const canEdit = board?.can.edit ?? false;

  // One autosave per opened board.
  useEffect(() => {
    if (!board || !userUuid) return;
    tab.current = tabId();
    const autosave = createAutosave({
      boardUuid: board.uuid,
      userUuid,
      tab: tab.current,
      put: (page, body) => boards.saveScene(board.uuid, page, body),
      putKeepalive: (page, body) => boards.saveSceneKeepalive(board.uuid, page, body),
      drafts: indexedDbDrafts,
      idle: (fn) => {
        if (typeof window.requestIdleCallback === "function") {
          const id = window.requestIdleCallback(fn, { timeout: 2000 });
          return () => window.cancelIdleCallback(id);
        }
        const id = setTimeout(fn, 0); // Safari has no requestIdleCallback
        return () => clearTimeout(id);
      },
      onState: setSaveState,
      onConflict: (page, server) => setConflict({ page, ...server }),
      onLockLost: () => {
        heldRef.current = false;
        setHeld(false);
        setRefusal("lock_lost");
      },
      onRefused: setRefusal,
    });
    save.current = autosave;
    trackAll(board);
    if (board.can.edit) offerDrafts(board);

    return () => autosave.stop();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- once per board and user
  }, [boardUuid, userUuid]);

  // The heartbeat: acquire, renew, or learn who holds it — every 5 seconds.
  useEffect(() => {
    if (!boardUuid || !canEdit || !userUuid) return;
    let alive = true;
    let releasing = false;

    const beat = async () => {
      if (releasing) return;
      try {
        const answer = await boards.lock(boardUuid, tab.current);
        if (!alive) return;

        if (!heldRef.current && everHeld.current) {
          // The editor's tab closed or handed over: this tab's copy is stale. The
          // fresh pages are tracked again (a page added meanwhile had no slot and
          // could never be saved), and any draft from before is offered back.
          const fresh = await boards.show(boardUuid);
          pagesRef.current.reload(fresh);
          trackAll(fresh);
          offerDrafts(fresh);
        }
        heldRef.current = true;
        everHeld.current = true;
        setHeld(true);
        setHeldBy(null);
        setTaking(false);
        save.current?.setHolding(true);

        if (answer.handover_requested) {
          releasing = true;
          setHandoverRequested(true);
          save.current?.saveNow();
          await save.current?.drain();
          save.current?.setHolding(false);
          await boards.releaseLock(boardUuid, tab.current);
          heldRef.current = false;
          setHeld(false);
          setHandoverRequested(false);
          releasing = false;
        }
      } catch (error) {
        if (!alive || !(error instanceof ApiError)) return;
        const body = error.body as { code?: string; held_by?: { name: string } | null } | null;
        if (body?.code === "locked") {
          heldRef.current = false;
          everHeld.current = true;
          setHeld(false);
          setHeldBy(body.held_by?.name ?? null);
          save.current?.setHolding(false);
        }
      }
    };

    void beat();
    const timer = window.setInterval(() => void beat(), HEARTBEAT_MS);
    return () => {
      alive = false;
      window.clearInterval(timer);
    };
  }, [boardUuid, canEdit, userUuid]);

  // Closing, hiding, and the network coming back.
  useEffect(() => {
    if (!boardUuid) return;
    const onHide = () => {
      const { unsaved } = save.current?.flush() ?? { unsaved: false };
      // Never release under a save still travelling: the release would land first.
      if (heldRef.current && !unsaved) void boards.releaseLock(boardUuid, tab.current);
    };
    const onShow = (event: PageTransitionEvent) => {
      if (event.persisted) save.current?.resume();
    };
    const onVisibility = () => {
      if (document.visibilityState === "hidden") save.current?.saveNow();
    };
    const onOnline = () => save.current?.resume();

    window.addEventListener("pagehide", onHide);
    window.addEventListener("pageshow", onShow);
    document.addEventListener("visibilitychange", onVisibility);
    window.addEventListener("online", onOnline);
    return () => {
      window.removeEventListener("pagehide", onHide);
      window.removeEventListener("pageshow", onShow);
      document.removeEventListener("visibilitychange", onVisibility);
      window.removeEventListener("online", onOnline);
    };
  }, [boardUuid]);

  const changed = useCallback((page: string, elements: readonly BoardElement[]) => {
    const p = pagesRef.current;
    save.current?.change(page, p.hash(elements), () => p.document(elements));
  }, []);

  const take = useCallback(() => {
    if (!boardUuid) return;
    setTaking(true);
    boards.takeLock(boardUuid, tab.current).catch(() => setTaking(false));
  }, [boardUuid]);

  // Side effects stay OUT of state updaters: React may run an updater twice.
  const restore = restores[0] ?? null;
  const answerRestore = (accept: boolean) => {
    if (restore && accept) {
      const p = pagesRef.current;
      const elements = p.replace(restore.page, restore.draft.scene);
      save.current?.restored(restore.page, p.hash(elements), () => p.document(elements));
    }
    setRestores((queue) => queue.slice(1));
  };

  const takeServer = () => {
    if (conflict) {
      const elements = pagesRef.current.replace(conflict.page, conflict.scene);
      save.current?.resolved(conflict.page, conflict.version, pagesRef.current.hash(elements), null);
    }
    setConflict(null);
  };

  return {
    held,
    heldBy,
    taking,
    handoverRequested,
    saveState,
    refusal,
    conflict,
    restore,
    changed,
    take,
    answerRestore,
    takeServer,
    dismissConflict: () => setConflict(null),
    pageRemoved: (page: string) => save.current?.remove(page),
    /** This tab's id — every structural request carries it. */
    tab: () => tab.current,
    /** Send what is waiting and let it land — before a page is copied, moved or deleted. */
    settle: async () => {
      save.current?.saveNow();
      await save.current?.drain();
    },
    /** A page the server just made (added, or a copy). */
    track: (page: string, version: number, elements: readonly BoardElement[]) =>
      save.current?.track(page, version, pagesRef.current.hash(elements)),
    /** A page whose elements this tab set itself and the server has not seen. */
    adopt: (page: string, elements: readonly BoardElement[]) => {
      const p = pagesRef.current;
      save.current?.restored(page, p.hash(elements), () => p.document(elements));
    },
  };
}
