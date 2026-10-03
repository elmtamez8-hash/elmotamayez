"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { boards, type BoardSummary } from "@/lib/whiteboard/api";
import { WB } from "@/lib/whiteboard/strings";

/**
 * «سبّورات الدرس» in the lesson editor (T096) — the second way in to a board,
 * after the boards list. The lesson's own boards, and a new one for it (its
 * course is derived from the lesson on the server). Someone the boards door
 * refuses sees nothing at all.
 */
export function LessonBoardsSection({ lessonUuid, lessonTitle }: { lessonUuid: string; lessonTitle: string }) {
  const [rows, setRows] = useState<BoardSummary[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    boards
      .list({ lesson: lessonUuid })
      .then((page) => alive && setRows(page.data))
      .catch(() => alive && setRows(null));
    return () => {
      alive = false;
    };
  }, [lessonUuid]);

  if (rows === null) return null;

  const create = async () => {
    // Opened in the click: a tab opened after an await is a blocked popup.
    const tab = window.open("", "_blank");
    setBusy(true);
    setFailed(false);
    try {
      const board = await boards.create({ title: lessonTitle, lesson: lessonUuid });
      setRows((current) => [board, ...(current ?? [])]);
      if (tab) tab.location.href = `/whiteboard/${board.uuid}`;
    } catch {
      tab?.close();
      setFailed(true);
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="space-y-2" aria-labelledby={`lesson-boards-${lessonUuid}`}>
      <h3 id={`lesson-boards-${lessonUuid}`} className="text-sm font-semibold">
        {WB.lessonBoards.title}
      </h3>
      {rows.length === 0 ? (
        <p className="text-sm text-ink-muted">{WB.lessonBoards.empty}</p>
      ) : (
        <ul className="space-y-1">
          {rows.map((board) => (
            <li key={board.uuid} className="flex items-center justify-between gap-2 text-sm">
              <span className="truncate">{board.title}</span>
              <Button size="sm" variant="ghost" href={`/whiteboard/${board.uuid}`} external>
                {WB.lessonBoards.open}
              </Button>
            </li>
          ))}
        </ul>
      )}
      <Button size="sm" variant="secondary" loading={busy} onClick={() => void create()}>
        {WB.lessonBoards.create}
      </Button>
      {failed && (
        <p role="alert" className="text-xs text-danger-ink">
          {WB.lessonBoards.failed}
        </p>
      )}
    </section>
  );
}
