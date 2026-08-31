"use client";

import { useEffect, useState } from "react";

import { Badge } from "@/components/ui/Badge";
import { listen } from "@/lib/echo";
import {
  boardChannel,
  type StudyRoomBoard as Board,
  type StudyRoomState,
} from "@/lib/study-rooms";

/**
 * The live scoreboard of one study room (FR-014 · SC-006).
 *
 * ⚠️ IT SUBSCRIBES WITH A FRESH CLOSURE PER CALL, never a `useCallback` with an
 * empty dependency array. pusher-js unbinds BY FUNCTION REFERENCE and removes
 * EVERY entry matching it, and React's development double-invoke produces two
 * `listen()` calls from one mount — so a shared reference binds twice and both
 * are torn out by the first cleanup. `listen()` wraps the handler for the same
 * reason; passing a stable function here would defeat that from the outside.
 *
 * ⚠️ AND `state` IS A PROP READ FROM THE SERVER'S PAYLOAD, NOT DERIVED FROM
 * `ends_at`. The countdown below ticks in the browser because a clock has to, but
 * whether the room is open is a comparison against the SERVER's clock — a device
 * a minute fast would otherwise refuse to show a room still taking answers, or
 * offer one that is over.
 *
 * ⚠️ AND THE FRAME CARRIES DATA, which is a recorded departure from this
 * product's «identifier only» broadcast rule with four conditions behind it. The
 * `uuid` on each row is the PARTICIPANT row's, never the person's.
 */
export function StudyRoomBoard({
  roomUuid,
  initial,
  questionCount,
  state,
}: {
  roomUuid: string;
  initial: Board;
  questionCount: number;
  state: StudyRoomState;
}) {
  const [board, setBoard] = useState<Board>(initial);
  const [remaining, setRemaining] = useState(() => secondsUntil(initial.ends_at));

  // A payload arriving from the server replaces the one this component was
  // handed; a room re-opened in a second tab must not show two boards.
  useEffect(() => setBoard(initial), [initial]);

  useEffect(() => {
    let release: (() => void) | undefined;
    let dropped = false;

    void listen<Board>(boardChannel(roomUuid), "board.updated", (payload) => {
      setBoard(payload);
    }).then((off) => {
      // The subscription resolved after this effect was torn down: release it
      // rather than leaving a listener on a channel nobody is watching.
      if (dropped) off();
      else release = off;
    });

    return () => {
      dropped = true;
      release?.();
    };
  }, [roomUuid]);

  useEffect(() => {
    if (state === "closed") return;

    const tick = setInterval(() => setRemaining(secondsUntil(board.ends_at)), 1000);

    return () => clearInterval(tick);
  }, [board.ends_at, state]);

  return (
    <section className="rounded-2xl border border-line bg-surface-raised p-5">
      <div className="mb-4 flex items-center justify-between gap-4">
        <h2 className="font-semibold text-ink">لوحة النتائج</h2>
        {state === "closed" ? (
          <Badge tone="neutral">انتهى الوقت</Badge>
        ) : (
          <Badge tone={remaining <= 60 ? "warning" : "info"}>
            <bdi>{clock(remaining)}</bdi>
          </Badge>
        )}
      </div>

      {board.rows.length === 0 ? (
        <p className="text-sm text-ink-muted">لم ينضمّ أحد بعد. شارك رابط الغرفة مع أصدقائك.</p>
      ) : (
        <ol className="space-y-2">
          {board.rows.map((row, index) => (
            <li
              key={row.uuid}
              className="flex items-center justify-between gap-3 rounded-lg border border-line p-3"
            >
              <span className="flex min-w-0 items-center gap-3">
                <span className="text-xs font-medium text-ink-muted">
                  <bdi>{index + 1}</bdi>
                </span>
                <span className="truncate text-sm font-medium text-ink">{row.name}</span>
              </span>
              <span className="flex shrink-0 items-center gap-2 text-xs text-ink-muted">
                <span>
                  <bdi>
                    {row.answered}/{questionCount}
                  </bdi>{" "}
                  سؤالاً
                </span>
                <Badge tone="success">
                  <bdi>{row.score}</bdi>
                </Badge>
              </span>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

function secondsUntil(iso: string): number {
  return Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 1000));
}

/** `m:ss`, wrapped in `<bdi>` by the caller so the digits do not reorder in RTL. */
function clock(seconds: number): string {
  const minutes = Math.floor(seconds / 60);

  return `${minutes}:${String(seconds % 60).padStart(2, "0")}`;
}
