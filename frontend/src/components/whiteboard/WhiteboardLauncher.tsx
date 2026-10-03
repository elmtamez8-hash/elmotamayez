"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Field";
import { boards, type BoardSummary } from "@/lib/whiteboard/api";
import { WB } from "@/lib/whiteboard/strings";

/** Its own window, 16:9, named so a second press brings the same one forward. */
const WINDOW = ["whiteboard", "popup,width=1600,height=900"] as const;

/**
 * «افتح السبّورة» in the live class (story 7), for the HOST only — the room
 * page renders it inside its host branch. The board opens in a window of its
 * own with the one-line hint to share THAT tab, not the whole screen.
 *
 * With no board linked to the class, the host picks one of theirs (linking it)
 * or makes a new one for it (US7-3). Someone the boards door refuses (no
 * `lessons.manage`) sees nothing at all.
 */
export function WhiteboardLauncher({ sessionUuid, sessionTitle }: { sessionUuid: string; sessionTitle: string }) {
  const [linked, setLinked] = useState<BoardSummary[] | null>(null);
  const [mine, setMine] = useState<BoardSummary[]>([]);
  const [choosing, setChoosing] = useState(false);
  const [picked, setPicked] = useState("");
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    boards
      .list({ session: sessionUuid })
      .then((page) => alive && setLinked(page.data))
      .catch(() => alive && setLinked(null));
    return () => {
      alive = false;
    };
  }, [sessionUuid]);

  if (linked === null) return null;

  /**
   * The window is opened IN the click, before any request: a window opened
   * after an await is a popup the browser blocks.
   */
  const open = async (board: () => Promise<string>) => {
    const win = window.open("", ...WINDOW);
    // The named window may already hold the board being SHARED: only a blank
    // one this press opened may be closed on a failure.
    const fresh = win !== null && win.location.href === "about:blank";
    setBusy(true);
    setFailed(false);
    try {
      const uuid = await board();
      const path = `/whiteboard/${uuid}`;
      // Already on it: brought forward, not reloaded mid-share.
      if (win && win.location.pathname === path) win.focus();
      else if (win) win.location.href = path;
      else window.open(path, ...WINDOW);
      // The class has its board now: the next press opens it, never makes another.
      setLinked((rows) => (rows?.some((row) => row.uuid === uuid) ? rows : [{ uuid } as BoardSummary, ...(rows ?? [])]));
      setChoosing(false);
    } catch {
      if (fresh) win.close();
      setFailed(true);
    } finally {
      setBusy(false);
    }
  };

  const choose = () => {
    setChoosing(true);
    boards
      .list({ mine: true })
      // A board another class already uses is not offered: linking would move it silently.
      .then((page) => setMine(page.data.filter((board) => !board.class_session || board.class_session.uuid === sessionUuid)))
      .catch(() => setMine([]));
  };

  return (
    <div className="mt-4 space-y-2">
      {linked.length > 0 ? (
        <Button size="sm" variant="secondary" onClick={() => void open(async () => linked[0].uuid)}>
          {WB.live.open}
        </Button>
      ) : !choosing ? (
        <Button size="sm" variant="secondary" onClick={choose}>
          {WB.live.open}
        </Button>
      ) : (
        <div className="space-y-2">
          {mine.length > 0 && (
            <div className="flex flex-wrap items-end gap-2">
              <SelectField
                id="wb-live-pick"
                label={WB.live.pick}
                value={picked}
                onChange={setPicked}
                placeholder={WB.live.choose}
                options={mine.map((board) => ({ value: board.uuid, label: board.title }))}
              />
              <Button
                size="sm"
                disabled={!picked}
                loading={busy}
                onClick={() =>
                  void open(async () => {
                    await boards.update(picked, { class_session: sessionUuid });
                    return picked;
                  })
                }
              >
                {WB.live.linkAndOpen}
              </Button>
            </div>
          )}
          <Button
            size="sm"
            variant="secondary"
            loading={busy}
            onClick={() => void open(async () => (await boards.create({ title: sessionTitle, class_session: sessionUuid })).uuid)}
          >
            {WB.live.create}
          </Button>
        </div>
      )}
      <p className="text-xs text-ink-muted">{WB.shareHint}</p>
      {failed && (
        <p role="alert" className="text-xs text-danger-ink">
          {WB.live.failed}
        </p>
      )}
    </div>
  );
}
