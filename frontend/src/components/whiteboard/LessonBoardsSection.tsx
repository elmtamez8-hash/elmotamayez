"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { ApiError, errorMessage } from "@/lib/api";
import { boards, type BoardSummary } from "@/lib/whiteboard/api";
import { WB } from "@/lib/whiteboard/strings";

/**
 * «سبّورات الدرس» in the lesson editor (T096) — the second way in to a board,
 * after the boards list. The lesson's own boards, and a new one for it (its
 * course is derived from the lesson on the server). Someone the boards door
 * refuses sees nothing at all.
 */
export function LessonBoardsSection({ lessonUuid, lessonTitle }: { lessonUuid: string; lessonTitle: string }) {
  // `null` while loading and for someone the boards door refuses; "failed" when it could not be read.
  const [rows, setRows] = useState<BoardSummary[] | "failed" | null>(null);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    boards
      .list({ lesson: lessonUuid })
      .then((page) => alive && setRows(page.data))
      .catch((error) => alive && setRows(error instanceof ApiError && error.status === 403 ? null : "failed"));
    return () => {
      alive = false;
    };
  }, [lessonUuid]);

  if (rows === null) return null;
  if (rows === "failed") {
    return (
      <p role="alert" className="text-xs text-danger-ink">
        {WB.lessonBoards.loadFailed}
      </p>
    );
  }

  const create = async () => {
    // Opened in the click: a tab opened after an await is a blocked popup.
    const tab = window.open("", "_blank");
    setBusy(true);
    setFailed(null);
    try {
      const board = await boards.create({ title: lessonTitle, lesson: lessonUuid });
      // A blocked tab still leaves the new board in the list, with its «افتح».
      setRows((current) => [board, ...(Array.isArray(current) ? current : [])]);
      if (tab) tab.location.href = `/whiteboard/${board.uuid}`;
    } catch (error) {
      tab?.close();
      setFailed(errorMessage(error, WB.lessonBoards.failed));
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
          {failed}
        </p>
      )}
    </section>
  );
}
