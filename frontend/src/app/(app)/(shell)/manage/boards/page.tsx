"use client";

import { useCallback, useEffect, useState } from "react";

import { DocumentIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField, TextField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { RecordList, RecordRow } from "@/components/ui/RecordList";
import { RequirePermission } from "@/components/ui/states/RefusedState";
import { api, ApiError } from "@/lib/api";
import { P } from "@/lib/permissions";
import { boards, type BoardSummary } from "@/lib/whiteboard/api";
import { WB } from "@/lib/whiteboard/strings";

/**
 * The teacher's boards (spec 039 · US1): create one, open one — always in a NEW TAB,
 * because the board is the tab the teacher shares, and because Excalidraw's CSS is
 * unlayered and would stay behind in this one after a client-side navigation.
 */
export default function ManageBoardsPage() {
  return (
    <RequirePermission permission={P.lessonsManage}>
      <BoardsScreen />
    </RequirePermission>
  );
}

const boardHref = (uuid: string) => `/whiteboard/${uuid}`;

function BoardsScreen() {
  const [list, setList] = useState<BoardSummary[] | null>(null);
  const [courses, setCourses] = useState<{ uuid: string; title: string }[]>([]);
  const [title, setTitle] = useState("");
  const [course, setCourse] = useState("");
  const [query, setQuery] = useState("");
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    boards
      .list({ q: query || undefined })
      .then((page) => setList(page.data))
      .catch(() => setError(WB.loadFailed));
  }, [query]);

  useEffect(load, [load]);

  useEffect(() => {
    api
      .get<{ data: { uuid: string; title: string }[] }>("/courses?per_page=200")
      .then((page) => setCourses(page.data))
      .catch(() => setCourses([]));
  }, []);

  const create = async () => {
    if (title.trim() === "") return;
    setCreating(true);
    setError(null);
    try {
      const board = await boards.create({ title: title.trim(), course: course || undefined });
      setTitle("");
      window.open(boardHref(board.uuid), "_blank", "noopener");
      load();
    } catch (failure) {
      setError(failure instanceof ApiError && failure.status === 422 ? failure.message : WB.loadFailed);
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="space-y-6">
      <PageHeader title={WB.boards} Icon={DocumentIcon} description={WB.shareHint} />

      <Card>
        <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <TextField id="board-title" label={WB.title} value={title} onChange={setTitle} maxLength={160} />
          <SelectField
            id="board-course"
            label="الكورس"
            value={course}
            onChange={setCourse}
            placeholder="بلا كورس"
            options={courses.map((c) => ({ value: c.uuid, label: c.title }))}
          />
          <Button loading={creating} onClick={create}>
            {WB.newBoard}
          </Button>
        </div>
      </Card>

      {error && <Alert tone="danger" title={error} />}

      <TextField id="board-search" type="search" label="ابحث بالعنوان" value={query} onChange={setQuery} />

      {list === null ? (
        <p className="text-sm text-ink-muted">{WB.loading}</p>
      ) : list.length === 0 ? (
        <p className="text-sm text-ink-muted">لا سبّورات بعد. أنشئ أول سبّورة من الأعلى.</p>
      ) : (
        <RecordList label={WB.boards}>
          {list.map((board) => (
            <RecordRow
              key={board.uuid}
              Icon={DocumentIcon}
              title={board.title}
              description={[board.course?.title, board.lesson?.title].filter(Boolean).join(" · ") || undefined}
              meta={[{ key: "pages", label: "عدد الصفحات", value: WB.pages(board.pages_count), labelHidden: true }]}
              actions={
                <Button size="sm" href={boardHref(board.uuid)} external>
                  افتح
                </Button>
              }
            />
          ))}
        </RecordList>
      )}
    </div>
  );
}
