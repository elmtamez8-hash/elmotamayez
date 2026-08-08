"use client";

import { useCallback, useEffect, useState } from "react";
import { AttachmentsPanel } from "./AttachmentsPanel";
import { ArticleEditor } from "./editors/ArticleEditor";
import { AudioEditor } from "./editors/AudioEditor";
import { DocumentEditor } from "./editors/DocumentEditor";
import { ExamPicker } from "./editors/ExamPicker";
import { LinkEditor } from "./editors/LinkEditor";
import { LiveSessionPicker } from "./editors/LiveSessionPicker";
import { NoteEditor } from "./editors/NoteEditor";
import { Alert } from "@/components/ui/Alert";
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

/** Types whose body is text and is saved by the button at the bottom. */
const INLINE: LessonTypeValue[] = ["article", "note", "link"];

/** Types whose own file is a document, whatever the label on the type says. */
const DOCUMENT: LessonTypeValue[] = ["pdf", "file"];

/**
 * The types that still have no editor here, each naming what brings it.
 *
 * Kept honest deliberately: this spec started because an empty state told
 * teachers to add content from a panel where it could not be added. A "not yet"
 * message left standing over a working editor is the same bug wearing the
 * opposite face.
 */
const PENDING: Partial<Record<LessonTypeValue, string>> = {
  video: "الفيديو له صفحته الخاصة — الرفع والترجمات والمشاهدة المحميّة.",
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
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setError("");

    courses
      .lesson(courseUuid, lessonUuid)
      .then((detail) => {
        setLesson(detail);
        setContent(detail.content ?? "");
        setUrl(detail.external_url ?? "");
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
          content: INLINE.includes(lesson.type) && lesson.type !== "link" ? content : undefined,
          external_url: lesson.type === "link" ? url : undefined,
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

      if (!window.confirm(warning)) {
        setBusy(false);

        return;
      }
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر قراءة أثر تغيير النوع."));
      setBusy(false);

      return;
    }

    setBusy(false);
    void run(() => courses.changeLessonType(courseUuid, lesson.uuid, next), "تغيّر نوع العنصر.");
  };

  const pending = PENDING[lesson.type];

  return (
    <Card as="section">
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
            hint="تغيير النوع يعرض ما سيُفقد قبل التنفيذ، ولا يُسمح به على عنصر منشور."
            value={lesson.type}
            disabled={busy || lesson.status === "published"}
            options={TYPE_OPTIONS}
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

        {DOCUMENT.includes(lesson.type) && (
          <DocumentEditor lessonUuid={lesson.uuid} asset={lesson.asset} onChanged={load} />
        )}

        {lesson.type === "audio" && (
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
          <Alert tone="info" title="محرّر هذا النوع لم يصل بعد">
            {pending}
            {lesson.type === "video" && (
              <span className="mt-2 block">
                <Button href={`/manage/courses/${courseUuid}/lessons/${lesson.uuid}`} size="sm">
                  صفحة الفيديو
                </Button>
              </span>
            )}
          </Alert>
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
                  يفتحه أي زائر من صفحة الكورس دون أن يسجّل فيه. إتاحة فعلية، لا وسم تسويقي.
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
                  للمسجَّلين وحدهم؛ لا يفتحه زائر. اجعله «متاح بلا تسجيل» إن أردت ذلك.
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

        {INLINE.includes(lesson.type) && (
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
