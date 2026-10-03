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
    setBusy(true);
    setFailed(false);
    try {
      const uuid = await board();
      if (win) win.location.href = `/whiteboard/${uuid}`;
      else window.open(`/whiteboard/${uuid}`, ...WINDOW);
    } catch {
      win?.close();
      setFailed(true);
    } finally {
      setBusy(false);
    }
  };

  const choose = () => {
    setChoosing(true);
    boards
      .list({ mine: true })
      .then((page) => setMine(page.data))
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
