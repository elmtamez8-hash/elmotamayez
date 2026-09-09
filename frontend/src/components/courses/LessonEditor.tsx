"use client";

import { useCallback, useEffect, useState } from "react";
import { AttachmentsPanel } from "./AttachmentsPanel";
import { ArticleEditor } from "./editors/ArticleEditor";
import { AudioEditor } from "./editors/AudioEditor";
import { DocumentEditor } from "./editors/DocumentEditor";
import { EmbedEditor } from "./editors/EmbedEditor";
import { ExamPicker } from "./editors/ExamPicker";
import { LinkEditor } from "./editors/LinkEditor";
import { LiveSessionPicker } from "./editors/LiveSessionPicker";
import { NoteEditor } from "./editors/NoteEditor";
import { VideoEditor } from "./editors/VideoEditor";
import { Alert } from "@/components/ui/Alert";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckboxField, SelectField } from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage } from "@/lib/api";
import { courses, type LessonDetail, type LessonTypeValue } from "@/lib/courses";

/**
 * One item, edited by the editor its own type needs.
 *
 * A single form carrying every field for every type is the thing this replaces:
 * it asks the teacher to work out which half is theirs, and it puts a URL box on
 * a video and a file picker on a notice.
 *
 * The type selector reads what a change would cost BEFORE performing it — the
 * server answers that question, so the warning cannot drift from what actually
 * happens.
 */

/*
 * There is deliberately no type list here any more.
 *
 * This file held `INLINE` and `DOCUMENT` — the registry's job done a second time
 * in another language. `LessonTypeRegistry` exists so that "what each type IS"
 * has one answer, and it described ten types in PHP while five of them were
 * described again, differently, in the browser: adding a type meant remembering
 * a file the registry says nothing about, and nothing would have failed.
 *
 * The API now sends `family` and `asset_kind` with every item, so every branch
 * below asks the registry through the payload.
 */

/**
 * The types that still have no editor here, each naming what brings it.
 *
 * Kept honest deliberately: this spec started because an empty state told
 * teachers to add content from a panel where it could not be added. A "not yet"
 * message left standing over a working editor is the same bug wearing the
 * opposite face.
 */
/*
  ⚠️ VIDEO WAS HERE, POINTING AT A PAGE OF ITS OWN, AND THAT IS WHERE THE BUG CAME
  FROM. Sending one type somewhere else meant a second screen that knew nothing
  about types — the course list linked EVERY item to it, so opening an article
  offered to upload a video for it, and the server refused a request the screen
  should never have made. Video renders here now, like every other uploaded kind.
*/
const PENDING: Partial<Record<LessonTypeValue, string>> = {
  assignment: "الواجبات تصل مع بنك الأسئلة.",
};

/**
 * The types the selector offers, and the one it offers WITHOUT letting it be
 * chosen.
 *
 * `assignment` is listed and disabled with its reason attached (FR-046). Hiding
 * it would be honest about today and silent about the plan; letting it be picked
 * would be a choice that saves and then does nothing. Disabled with a sentence is
 * the only reading that is true of both.
 */
const TYPE_OPTIONS: Array<{ value: LessonTypeValue; label: string; disabled?: boolean }> = [
  { value: "article", label: "مقالة" },
  { value: "note", label: "تنويه" },
  { value: "link", label: "رابط خارجي" },
  { value: "video", label: "فيديو" },
  { value: "audio", label: "صوت" },
  { value: "pdf", label: "مستند PDF" },
  { value: "file", label: "ملف" },
  { value: "exam", label: "اختبار" },
  { value: "live_session", label: "حصة مباشرة" },
  { value: "assignment", label: "واجب — يصل مع بنك الأسئلة", disabled: true },
];

/**
 * Spec 032 · FR-007 — «فيديو مُضمَّن» is offered only to a lesson that is
 * already open.
 *
 * ⚠️ HIDDEN, NOT DISABLED, AND THAT IS THE OPPOSITE CALL FROM `assignment`
 * ABOVE — for the reason that decides between them. `assignment` is unavailable
 * to everybody until a whole spec ships, so a disabled row with its reason is
 * the only honest reading. This option is unavailable for a condition the
 * teacher can change on THIS screen: ticking «متاح بلا تسجيل» two sections down
 * makes it appear. The select's hint says so, so its absence is not a mystery.
 *
 * ⚠️ AND IT IS THE SECOND HALF OF A GUARD, NEVER THE GUARD (FR-007). The server
 * refuses to publish a locked embed whatever this list does — hiding a control
 * is not a guard, and a second API reader carries none of these rules.
 */
function typeOptionsFor(lesson: LessonDetail) {
  const open = lesson.is_preview || lesson.is_free;

  return open
    ? [...TYPE_OPTIONS, { value: "embed" as LessonTypeValue, label: "فيديو مُضمَّن" }]
    : TYPE_OPTIONS;
}

export function LessonEditor({
  courseUuid,
  lessonUuid,
  onSaved,
  onClose,
}: {
  courseUuid: string;
  lessonUuid: string;
  onSaved: () => void;
  onClose: () => void;
}) {
  const [lesson, setLesson] = useState<LessonDetail | null>(null);
  const [content, setContent] = useState("");
  const [url, setUrl] = useState("");
  // A STRING, not a number: an empty box has to stay empty while it is being
  // typed in, and `0` is what «not written» is stored as (FR-018).
  const [duration, setDuration] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  /*
    The type change waiting on an answer, with the cost already read (spec 033).

    ⚠️ IT HOLDS THE WARNING TEXT, not just the target type. The sentence names
    what disappears, and it was computed from the server's preview — recomputing
    it at render time would be a second derivation of one answer, which is how
    the teacher gets shown a cost that no longer matches the one that was read.
  */
  const [pendingType, setPendingType] = useState<{ next: LessonTypeValue; warning: string } | null>(
    null,
  );

  const load = useCallback(() => {
    setLoading(true);
    setError("");

    courses
      .lesson(courseUuid, lessonUuid)
      .then((detail) => {
        setLesson(detail);
        setContent(detail.content ?? "");
        setUrl(detail.external_url ?? "");
        setDuration(detail.duration_seconds > 0 ? String(detail.duration_seconds) : "");
      })
      .catch((err: unknown) => setError(errorMessage(err, "تعذّر تحميل العنصر.")))
      .finally(() => setLoading(false));
  }, [courseUuid, lessonUuid]);

  useEffect(load, [load]);

  if (loading) return <RowsSkeleton count={3} />;
  if (lesson === null) {
    return (
      <Alert tone="danger" title="تعذّر تحميل العنصر">
        {error || "أعد المحاولة."}
      </Alert>
    );
  }

  const run = async (work: () => Promise<LessonDetail>, message: string) => {
    setBusy(true);
    setError("");
    setNotice("");

    try {
      const fresh = await work();
      setLesson(fresh);
      setContent(fresh.content ?? "");
      setUrl(fresh.external_url ?? "");
      setDuration(fresh.duration_seconds > 0 ? String(fresh.duration_seconds) : "");
      setNotice(message);
      // The outline shows the type and the status; both can have just moved.
      onSaved();
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر حفظ التعديل."));
    } finally {
      setBusy(false);
    }
  };

  const save = () =>
    void run(
      () =>
        courses.updateLesson(courseUuid, lesson.uuid, {
          content: lesson.family === "inline" ? content : undefined,
          external_url: lesson.family === "external" ? url : undefined,
          // Only the embedded type states its own duration — every other one
          // reads it off the uploaded file. An empty box is `0`, which every
          // reader treats as «not written» and prints as nothing.
          duration_seconds:
            lesson.type === "embed" ? Number.parseInt(duration, 10) || 0 : undefined,
        }),
      "حُفظ العنصر.",
    );

  const changeType = async (next: LessonTypeValue) => {
    if (next === lesson.type) return;

    setBusy(true);
    setError("");

    try {
      // Read the cost first. A confirm dialog that lists nothing is a dialog
      // people click through; this one names what disappears.
      const preview = await courses.typeChangePreview(courseUuid, lesson.uuid, next);

      const warning =
        preview.losses.length === 0
          ? `سيتحوّل العنصر إلى «${preview.type_label}». متابعة؟`
          : `سيتحوّل العنصر إلى «${preview.type_label}» وسيُفقد: ${preview.losses.join(" · ")}`;

      /*
        ⚠️ THE ORDER IS THE MEANING, NOT THE STYLE. The cost is read from the
        server BEFORE anything is shown, so the window can name what disappears —
        a confirmation that lists nothing is a confirmation people click through.
        Asking first and costing after would be a question about an answer nobody
        has yet.

        ⚠️ AND `busy` IS PUT DOWN BEFORE THE WINDOW OPENS. Left up, the type
        select stays disabled underneath it — so cancelling returns the teacher
        to a dead control that says nothing about why.
      */
      setBusy(false);
      setPendingType({ next, warning });

      return;
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر قراءة أثر تغيير النوع."));
      setBusy(false);

      return;
    }
  };

  const pending = PENDING[lesson.type];

  return (
    <Card as="section">
      <Modal
        open={pendingType !== null}
        title="تغيير نوع العنصر"
        message={pendingType?.warning}
        confirmLabel="غيّر النوع"
        tone="danger"
        onCancel={() => setPendingType(null)}
        onConfirm={() => {
          if (pendingType === null) return;

          const next = pendingType.next;

          setPendingType(null);
          void run(
            () => courses.changeLessonType(courseUuid, lesson.uuid, next),
            "تغيّر نوع العنصر.",
          );
        }}
      />

      <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="font-semibold text-ink">{lesson.title}</h3>
          <p className="mt-1 text-sm text-ink-muted">
            {lesson.type_label} — {lesson.status_label}
            {!lesson.is_completable && " · لا يُحتسب في نسبة التقدّم"}
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={onClose}>
          إغلاق
        </Button>
      </header>

      {error !== "" && (
        <div className="mb-4">
          <Alert tone="danger" title="تعذّر الحفظ">
            {error}
          </Alert>
        </div>
      )}

      {notice !== "" && (
        <div className="mb-4">
          <Alert tone="info" title="تمّ">
            {notice}
          </Alert>
        </div>
      )}

      <div className="space-y-5">
        {lesson.is_recording ? (
          <Alert tone="info" title="هذا العنصر تسجيل حصة">
            يشاهده من حجز مقعداً في تلك الحصة، لا كل من سجّل في الكورس — ولذلك لا يُحتسب في نسبة
            التقدّم ولا يمكن تغيير نوعه من هنا.
          </Alert>
        ) : (
          <SelectField
            id={`type-${lesson.uuid}`}
            label="نوع العنصر"
            hint="تغيير النوع يعرض ما سيُفقد قبل التنفيذ، ولا يُسمح به على عنصر منشور. و«فيديو مُضمَّن» يظهر بعد تعليم العنصر «متاح بلا تسجيل» أو «بلا مقابل»."
            value={lesson.type}
            disabled={busy || lesson.status === "published"}
            options={typeOptionsFor(lesson)}
            onChange={(value) => void changeType(value as LessonTypeValue)}
          />
        )}

        {lesson.type === "article" && (
          <ArticleEditor lesson={lesson} content={content} disabled={busy} onChange={setContent} />
        )}
        {lesson.type === "note" && (
          <NoteEditor lesson={lesson} content={content} disabled={busy} onChange={setContent} />
        )}
        {lesson.type === "link" && (
          <LinkEditor lesson={lesson} url={url} disabled={busy} onChange={setUrl} />
        )}
        {lesson.type === "embed" && (
          <EmbedEditor
            lesson={lesson}
            url={url}
            durationSeconds={duration}
            disabled={busy}
            onUrlChange={setUrl}
            onDurationChange={setDuration}
          />
        )}

        {lesson.asset_kind === "video" && (
          <VideoEditor lessonUuid={lesson.uuid} asset={lesson.asset} onChanged={load} />
        )}

        {lesson.asset_kind === "document" && (
          <DocumentEditor lessonUuid={lesson.uuid} asset={lesson.asset} onChanged={load} />
        )}

        {lesson.asset_kind === "audio" && (
          <AudioEditor lessonUuid={lesson.uuid} asset={lesson.asset} onChanged={load} />
        )}

        {lesson.type === "exam" && (
          <ExamPicker
            courseUuid={courseUuid}
            lesson={lesson}
            disabled={busy}
            onSave={(patch) =>
              void run(
                () => courses.updateLesson(courseUuid, lesson.uuid, patch),
                "حُفظ العنصر.",
              )
            }
          />
        )}

        {lesson.type === "live_session" && (
          <LiveSessionPicker
            courseUuid={courseUuid}
            lesson={lesson}
            disabled={busy}
            onSave={(patch) =>
              void run(
                () => courses.updateLesson(courseUuid, lesson.uuid, patch),
                "حُفظ العنصر.",
              )
            }
          />
        )}

        {pending !== undefined && (
          <Alert tone="info" title="محرّر هذا النوع لم يصل بعد">{pending}</Alert>
        )}

        {/*
          Two switches that are constantly mistaken for each other, so each one
          says what it actually does rather than what its name suggests.
        */}
        <div className="space-y-2">
          <CheckboxField
            id={`preview-${lesson.uuid}`}
            checked={lesson.is_preview}
            disabled={busy}
            label={
              <span>
                متاح بلا تسجيل
                <span className="block text-xs text-ink-muted">
                  {lesson.type === "embed"
                    ? "يفتحه أي زائر من صفحة الكورس دون أن يسجّل فيه. إتاحة فعلية، لا وسم تسويقي."
                    : "يفتحه المسجَّل ولو لم يبلغه بعد. الزائر بلا حساب لا يفتح إلا الدرس المُضمَّن."}
                </span>
              </span>
            }
            onChange={(checked) =>
              void run(
                () => courses.updateLesson(courseUuid, lesson.uuid, { is_preview: checked }),
                "حُفظ.",
              )
            }
          />

          <CheckboxField
            id={`free-${lesson.uuid}`}
            checked={lesson.is_free}
            disabled={busy}
            label={
              <span>
                بلا مقابل داخل الكورس
                <span className="block text-xs text-ink-muted">
                  {lesson.type === "embed"
                    ? "يفتحه أي زائر أيضاً — الدرس المُضمَّن مفتوح بأيّ من الوسمَين."
                    : "للمسجَّلين وحدهم؛ لا يفتحه زائر. اجعله «متاح بلا تسجيل» إن أردت ذلك."}
                </span>
              </span>
            }
            onChange={(checked) =>
              void run(
                () => courses.updateLesson(courseUuid, lesson.uuid, { is_free: checked }),
                "حُفظ.",
              )
            }
          />
        </div>

        {(lesson.family === "inline" || lesson.family === "external") && (
          <Button onClick={save} loading={busy} loadingLabel="جارٍ الحفظ">
            حفظ
          </Button>
        )}

        {/* On every type, including the ones with no editor of their own: a
            worksheet under a video is the ordinary case, not an edge one. */}
        <AttachmentsPanel
          lessonUuid={lesson.uuid}
          attachments={lesson.attachments}
          onChanged={load}
        />
      </div>
    </Card>
  );
}
