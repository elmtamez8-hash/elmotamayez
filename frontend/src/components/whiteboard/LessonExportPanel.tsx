"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Field";
import { api, ApiError, uploadToTicket } from "@/lib/api";
import { courses as courseApi } from "@/lib/courses";
import { media } from "@/lib/media";
import { boards, type BoardDetail, type BoardExport } from "@/lib/whiteboard/api";
import { WB } from "@/lib/whiteboard/strings";

type Phase = { kind: "idle" } | { kind: "drawing"; done: number; total: number } | { kind: "uploading" } | { kind: "done"; replaced: boolean } | { kind: "failed"; message: string };

/**
 * «مواد الدرس» (story 5): the whole board as one PDF in a lesson's attachments.
 * The PDF is drawn in this browser (`renderPdf`), uploaded through the lesson's
 * OWN attachment door, then recorded on the board — so exporting again
 * REPLACES the file instead of adding a second one (Q2).
 *
 * Whether the reader may replace is the server's answer (`can_replace`), read
 * BEFORE anything is drawn or uploaded: an assistant who may not delete
 * attachments is told «اطلب من مدرّس الكورس» rather than refused after a minute.
 */
export function LessonExportPanel({
  board,
  pageCount,
  renderPdf,
}: {
  board: BoardDetail;
  pageCount: number;
  renderPdf: (onPage: (done: number) => void) => Promise<Blob>;
}) {
  const [exports, setExports] = useState<BoardExport[]>(board.exports ?? []);
  const [courses, setCourses] = useState<{ uuid: string; title: string }[]>([]);
  const [course, setCourse] = useState(board.course?.deleted ? "" : (board.course?.uuid ?? ""));
  const [lessons, setLessons] = useState<{ uuid: string; title: string }[] | null>(null);
  const [lesson, setLesson] = useState(board.lesson?.uuid ?? "");
  const [phase, setPhase] = useState<Phase>({ kind: "idle" });

  useEffect(() => {
    api
      .get<{ data: { uuid: string; title: string }[] }>("/courses?per_page=200")
      .then((page) => setCourses(page.data))
      .catch(() => setCourses([]));
  }, []);

  useEffect(() => {
    setLessons(null);
    if (!course) return;
    let alive = true;
    // The AUTHOR's tree (the student's curriculum answers staff «not enrolled»), in its own order.
    courseApi
      .tree(course)
      .then(
        (tree) =>
          alive &&
          setLessons(tree.sections.flatMap((section) => section.chapters.flatMap((chapter) => chapter.lessons.map(({ uuid, title }) => ({ uuid, title }))))),
      )
      .catch(() => alive && setLessons([]));
    return () => {
      alive = false;
    };
  }, [course]);

  const existing = exports.find((row) => row.lesson?.uuid === lesson);
  const attached = existing?.attachment != null;
  const blocked = attached && !existing.can_replace;
  const busy = phase.kind === "drawing" || phase.kind === "uploading";

  const attach = async () => {
    if (!lesson || blocked) return;
    try {
      setPhase({ kind: "drawing", done: 0, total: pageCount });
      const pdf = await renderPdf((done) => setPhase({ kind: "drawing", done, total: pageCount }));
      setPhase({ kind: "uploading" });
      const file = new File([pdf], `${board.title}.pdf`, { type: "application/pdf" });
      const { asset, upload } = await media.requestUpload(lesson, {
        original_filename: file.name,
        size_bytes: file.size,
        kind: "document",
        role: "attachment",
      });
      await uploadToTicket(upload, file); // same-origin when it is our own URL, as the board's pictures
      const settled = await media.complete(asset.uuid);
      if (settled.status !== "ready") throw new Error("asset-not-ready");

      const recorded =
        existing && attached
          ? await boards.replaceExport(board.uuid, existing.uuid, { asset: asset.uuid })
          : await boards.recordExport(board.uuid, { lesson, asset: asset.uuid });
      const title = lessons?.find((row) => row.uuid === lesson)?.title ?? existing?.lesson?.title ?? "";
      setExports((rows) => [
        ...rows.filter((row) => row.uuid !== recorded.export),
        {
          uuid: recorded.export,
          lesson: { uuid: lesson, title },
          attachment: recorded.attachment.uuid ? { uuid: recorded.attachment.uuid } : null,
          can_replace: existing?.can_replace ?? true,
        },
      ]);
      setPhase({ kind: "done", replaced: recorded.replaced });
    } catch (error) {
      const code = error instanceof ApiError ? (error.body as { code?: string } | null)?.code : undefined;
      setPhase({
        kind: "failed",
        message: code && code in WB.errors ? WB.errors[code as keyof typeof WB.errors] : error instanceof ApiError ? error.message : WB.pagesFailed,
      });
    }
  };

  return (
    <div className="flex flex-col gap-2 border-t border-line pt-1.5 text-sm">
      <SelectField
        id="wb-export-course"
        label={WB.lessonExport.course}
        value={course}
        onChange={(value) => {
          setCourse(value);
          setLesson("");
        }}
        placeholder={WB.lessonExport.chooseCourse}
        options={courses.map((row) => ({ value: row.uuid, label: row.title }))}
        disabled={busy}
      />
      {course && lessons?.length === 0 && <p className="text-xs text-ink-muted">{WB.lessonExport.noLessons}</p>}
      {course && lessons && lessons.length > 0 && (
        <SelectField
          id="wb-export-lesson"
          label={WB.lessonExport.lesson}
          value={lesson}
          onChange={setLesson}
          placeholder={WB.lessonExport.chooseLesson}
          options={lessons.map((row) => ({ value: row.uuid, label: row.title }))}
          disabled={busy}
        />
      )}
      {attached && <p className="text-xs text-ink-muted">{blocked ? WB.lessonExport.askTeacher : WB.lessonExport.attachedAlready}</p>}
      <Button size="sm" disabled={!lesson || blocked} loading={busy} onClick={attach}>
        {attached ? WB.lessonExport.replace : WB.lessonExport.attach}
      </Button>
      {(phase.kind === "drawing" || phase.kind === "uploading" || phase.kind === "done") && (
        <p role="status" className="text-xs">
          {phase.kind === "drawing" && WB.lessonExport.drawing(Math.min(phase.done + 1, phase.total), phase.total)}
          {phase.kind === "uploading" && WB.lessonExport.uploading}
          {phase.kind === "done" && (phase.replaced ? WB.lessonExport.replaced : WB.lessonExport.attached)}
        </p>
      )}
      {phase.kind === "failed" && (
        <p role="alert" className="text-xs text-danger-ink">
          {phase.message}
        </p>
      )}
    </div>
  );
}
