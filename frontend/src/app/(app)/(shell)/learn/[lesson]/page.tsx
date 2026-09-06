"use client";

import { use, useEffect, useState } from "react";

import { AttachmentList, type StudentAttachment } from "@/components/player/AttachmentList";
import { DocumentViewer } from "@/components/player/DocumentViewer";
import { VideoPlayer } from "@/components/player/VideoPlayer";
import { SessionChat } from "@/components/community/SessionChat";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ApiError, api } from "@/lib/api";
import type { ExamReference, SessionReference } from "@/lib/courses";
import { userMessage } from "@/lib/errors";
import { media, type PlaybackGrant } from "@/lib/media";
import { formatSessionTime } from "@/lib/session-format";

interface StudentLesson {
  uuid: string;
  title: string;
  type: string;
  type_label: string;
  is_completable: boolean;
  /**
   * هل يقولُ الطالبُ بنفسِه إنّه أتمَّه — لا مجرّدَ «هل يُحتسَب».
   *
   * ⚠️ الاثنانِ يختلفانِ عندَ `exam` وحدَه: عنصرُ الاختبارِ في المقامِ ويُكمَلُ
   * فعلاً، لكنّ الذي يُكملُه هو تسليمُ الورقة (`CompleteExamLessonOnSubmission`)
   * لا زرٌّ هنا — وزرٌّ عليه هو تخطّي الاختبارِ وتحريكُ النسبةِ بلا إجابةِ سؤال.
   * يُقرأُ من الخادمِ ولا يُشتقُّ من `type`: البابُ يرفضُ بالقاعدةِ نفسِها.
   */
  may_self_complete: boolean;
  is_completed: boolean;
  content: string | null;
  content_html: string;
  external_url: string | null;
  has_asset: boolean;
  attachments: StudentAttachment[];
  reference: ExamReference | SessionReference | null;
  exam_gate: "attempt" | "pass" | null;
  exam_gate_label: string | null;
}

interface LessonResponse {
  lesson: StudentLesson;
  can_access: boolean;
  /** Null when access was granted. Set to the reason, in words, when it was not. */
  blocked_reason: string | null;
  blocked_message: string | null;
  blocked_by_title: string | null;
  /** `null` في عرضِ المؤلِّف: لا تسجيلَ خلفَه، فلا شيءَ يُتَمّ. */
  enrollment_uuid: string | null;
}

/**
 * Opening one item.
 *
 * It played video and nothing else, so an article rendered as an empty player
 * and a PDF was unreachable — the enrolments page worked around it by refusing
 * to link anything but video, which is a workaround, not a fix.
 *
 * Whatever the type, a grant is requested on demand and never stored anywhere
 * durable: it expires in minutes, is bound to this sign-in, and dies the moment
 * the session does. A URL copied from the network tab is worth nothing to
 * anyone else, which is the whole point of the phase.
 */
export default function LearnLessonPage({
  params,
}: {
  params: Promise<{ lesson: string }>;
}) {
  const { lesson } = use(params);

  const [detail, setDetail] = useState<StudentLesson | null>(null);
  const [enrollmentUuid, setEnrollmentUuid] = useState<string | null>(null);
  const [blocked, setBlocked] = useState<LessonResponse | null>(null);
  const [grant, setGrant] = useState<PlaybackGrant | null>(null);
  const [error, setError] = useState("");
  const [preparing, setPreparing] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    api
      .get<LessonResponse>(`/learn/lessons/${lesson}`)
      .then((result) => {
        if (cancelled) return;

        // Held either way. A blocked item still has a title and a type, and the
        // student is owed the reason it is closed — dropping the payload was why
        // this page used to show a bare heading and nothing else (FR-043).
        setDetail(result.lesson);
        setBlocked(result.can_access ? null : result);
        setEnrollmentUuid(result.enrollment_uuid);
        setCompleted(result.lesson.is_completed);
      })
      // ⚠️ `.catch(() => undefined)` stood here, and it rendered NOTHING at all:
      // a 404 on a uuid that is not this viewer's — a teacher opening a student
      // route, a stale link — left the page permanently blank with the reason
      // sitting unread in the response. A swallowed error is worse than a raw
      // one; `userMessage` is what turns it into a sentence.
      .catch((err: unknown) => {
        if (!cancelled) setError(userMessage(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [lesson]);

  // The player is asked for only when there is something to play. Requesting a
  // grant for an article would burn one and answer 403 for the right reason at
  // the wrong time — and for a BLOCKED item it would answer 403 for the right
  // reason with the wrong sentence, over the top of the one the server gave.
  //
  // `detail !== null`, and the `detail === null` that used to stand there is the
  // bug this replaces: on the first render nothing is known yet, so the condition
  // was true for EVERY item and the grant request fired before the type came
  // back. On an article it answered 403, correctly, and the screen showed
  // "تعذّرت المشاهدة — لا تملك صلاحية لهذا الإجراء" in red over content that had
  // loaded and was perfectly readable. Waiting one render costs nothing: the
  // detail fetch is already in flight when this runs.
  const wantsPlayer =
    blocked === null && detail !== null && (detail.type === "video" || detail.type === "audio");

  useEffect(() => {
    let cancelled = false;

    if (!wantsPlayer) {
      setLoading(false);

      return;
    }

    media
      .requestPlayback(lesson)
      .then((result) => {
        if (!cancelled) setGrant(result);
      })
      .catch((err: unknown) => {
        if (cancelled) return;

        // 409 is not a failure: the viewer is entitled and the video is still
        // being prepared. Showing it as an error would send them to support over
        // something that fixes itself.
        if (err instanceof ApiError && err.status === 409) setPreparing(true);
        else setError(userMessage(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [lesson, wantsPlayer]);

  /*
   * ⚠️ إتمامُ الدرسِ — ولم يكنْ في المنتَجِ كلِّه ما يفعلُه. `POST
   * …/lessons/{lesson}/complete` قائمٌ منذُ ٠١٦ ولا ملفَّ واحدٍ في الواجهةِ
   * ينادِيه، فالفيديو والمقالُ لا يكتملانِ أبداً و«أتممتَ ٠ من ٣ — ٠٪» هي
   * الحالةُ الوحيدةُ التي يبلغُها طالب — ومعَها لا `CourseCompleted` ولا شهادة.
   * بلاغُ ٢٠٢٦-٠٩-٠٦.
   *
   * ⚠️ ولا إتمامَ تلقائيٌّ عندَ نهايةِ الفيديو: طُلِبَ زرّ، والتلقائيُّ يُعلِنُ
   * الإتمامَ عمّن تركَ التبويبَ مفتوحاً. ولا تراجعَ كذلك — لا نقطةَ نهايةٍ له،
   * و`MarkLessonComplete` يعودُ مبكّراً على صفٍّ مكتمل: الإتمامُ نهائيّ.
   */
  const [completed, setCompleted] = useState(false);
  const [completing, setCompleting] = useState(false);
  const [completeError, setCompleteError] = useState("");
  const [celebrate, setCelebrate] = useState(false);

  const markComplete = async () => {
    if (enrollmentUuid === null) return;

    setCompleting(true);
    setCompleteError("");

    try {
      const result = await api.post<{
        status: string;
        course_completed: boolean;
        progress_pct: number;
      }>(`/enrollments/${enrollmentUuid}/lessons/${lesson}/complete`);

      // ⚠️ لا مفتاحَ `is_completed` في هذا الردّ — `status === "completed"` هو
      // الفحص. وقراءةُ مفتاحٍ غيرِ موجودٍ تُعطي `undefined` فيبقى الزرُّ كما هو
      // بلا خطأ، وهو أسوأُ من رفضٍ صريح.
      setCompleted(result.status === "completed");
      setCelebrate(result.course_completed);
    } catch (err: unknown) {
      setCompleteError(userMessage(err));
    } finally {
      setCompleting(false);
    }
  };

  const open = blocked === null && detail !== null;
  const isDocument = open && detail !== null && (detail.type === "pdf" || detail.type === "file");

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-xl font-bold text-ink">{detail?.title ?? "الدرس"}</h1>
      {detail !== null && (
        <p className="-mt-4 text-sm text-ink-muted">
          {detail.type_label}
          {!detail.is_completable && " · لا يُحتسب في نسبة تقدّمك"}
        </p>
      )}

      {/*
        Why it is closed, and what opens it. An exam gate is invisible from here
        otherwise: what has to happen is on a different page, so "مغلق" alone
        leaves the student with nowhere to go (FR-043).
      */}
      {blocked !== null && (
        <Alert tone="warning" title="هذا الدرس غير مفتوح بعد">
          {blocked.blocked_message ?? "أكمِل ما قبله أولاً."}
          {(blocked.blocked_reason === "exam_pass" || blocked.blocked_reason === "exam_attempt") && (
            <span className="mt-2 block">
              <Button href="/exams" size="sm" variant="secondary">
                اختباراتي
              </Button>
            </span>
          )}
        </Alert>
      )}

      {loading && blocked === null && <p className="text-sm text-ink-muted">جارٍ التحضير…</p>}

      {preparing && (
        <Alert tone="info" title="الفيديو قيد التجهيز">
          الفيديو ما زال قيد المعالجة. حدّث الصفحة بعد قليل.
        </Alert>
      )}

      {error !== "" && (
        <Alert tone="danger" title="تعذّرت المشاهدة">
          {error}
        </Alert>
      )}

      {grant !== null && wantsPlayer && (
        <Card padding="sm">
          <VideoPlayer grant={grant} />
        </Card>
      )}

      {isDocument && detail !== null && (
        <Card padding="sm">
          <DocumentViewer lessonUuid={lesson} filename={detail.title} />
        </Card>
      )}

      {/*
        A reference item is a POSITION, so what it shows is a way in — never a
        copy of the exam or a second player for the session.
      */}
      {open && detail !== null && detail.type === "exam" && detail.reference !== null && (
        <Card>
          <p className="mb-3 text-sm text-ink-muted">
            {"passing_score" in detail.reference &&
              `النجاح من ${detail.reference.passing_score}٪ · ${detail.reference.duration_minutes} دقيقة`}
            {detail.exam_gate === "pass" && " · لا يُفتح ما بعده حتى تجتازه"}
          </p>
          <Button href={`/exams/${detail.reference.uuid}`}>فتح الاختبار</Button>
        </Card>
      )}

      {open && detail !== null && detail.type === "live_session" && detail.reference !== null && (
        <Card>
          <SessionSlot reference={detail.reference} />
        </Card>
      )}

      {open && detail !== null && (detail.type === "article" || detail.type === "note") && (
        <Card>
          {/* Rendered from Markdown on the server with raw HTML stripped, not
              escaped — the same string every reader is served. The page used to
              show the SOURCE, asterisks and all. */}
          <div
            className="text-ink"
            dangerouslySetInnerHTML={{ __html: detail.content_html }}
          />
        </Card>
      )}

      {open && detail !== null && detail.type === "link" && detail.external_url !== null && (
        <Card>
          <p className="mb-3 text-sm text-ink-muted">
            هذا المحتوى على موقع خارجي — خارج حماية المنصّة، ولا يُحتسب في نسبة تقدّمك.
          </p>
          <Button href={detail.external_url} external>
            فتح الرابط
          </Button>
        </Card>
      )}

      {/*
        ⚠️ الضابطُ الذي لم يكنْ موجوداً. شرطُه أربعةٌ وكلٌّ منها يمنعُ حالةً حقيقيّة:
        `open` (درسٌ مغلقٌ لا يُتَمّ)، و`may_self_complete` (الاختبارُ يكتملُ
        بالتسليمِ والخادمُ يرفضُ غيرَ ذلك)، و`enrollmentUuid` (المؤلِّفُ يفتحُ هذه
        الصفحةَ بلا تسجيلٍ فلا شيءَ له أن يُتِمَّه)، وأخيراً حالةُ الإتمامِ نفسُها.
      */}
      {open && detail !== null && detail.may_self_complete && enrollmentUuid !== null && (
        <Card padding="sm">
          {completeError !== "" && (
            <div className="mb-3">
              <Alert tone="danger" title="لم يُسجَّل الإتمام">{completeError}</Alert>
            </div>
          )}

          {completed ? (
            <p className="text-sm font-medium text-secondary-ink">
              ✓ أتممتَ هذا الدرس — احتُسب في نسبة تقدّمك.
            </p>
          ) : (
            <div className="flex flex-wrap items-center gap-3">
              <Button type="button" loading={completing} loadingLabel="جارٍ الحفظ…" onClick={() => void markComplete()}>
                علِّمه مكتملاً
              </Button>
              <span className="text-sm text-ink-muted">
                يرفع نسبة تقدّمك في الكورس، ويفتح ما بعده في المسار المتسلسل.
              </span>
            </div>
          )}

          {celebrate && (
            <div className="mt-3">
              {/* الشهادةُ معلَّقةٌ على `CourseCompleted`، فهذه اللحظةُ تستحقُّ سطراً. */}
              <Alert tone="success" title="أكملتَ الكورس كلّه">
                تصلك شهادتك في «شهاداتي» بعد قليل.
              </Alert>
            </div>
          )}
        </Card>
      )}

      {/*
        عنصرُ الاختبارِ يُحتسَبُ ولا يُعلَنُ باليد — والصمتُ عنه هو نصفُ البلاغِ
        الأصليّ: «مافيش حاجة بتقول اني اكملت الدرس».
      */}
      {open && detail !== null && detail.is_completable && !detail.may_self_complete && (
        <p className="text-sm text-ink-muted">
          {completed ? "✓ اكتمل هذا العنصر." : "يكتمل هذا العنصر تلقائياً عند تسليم الاختبار."}
        </p>
      )}

      {open && detail !== null && (
        <AttachmentList lessonUuid={lesson} attachments={detail.attachments} />
      )}

      {/*
        Spec 010 · US3 — the room under the lesson.

        ⚠️ NOT GATED ON `open` HERE. The room is entitled by ENROLMENT in the
        course, which is a coarser question than whether this particular item is
        unlocked — a student stuck behind an exam gate is exactly the person with
        a question to ask about it. `SessionChat` renders nothing for anyone the
        server refuses, so there is no second condition to keep in step.
      */}
      <SessionChat kind="lesson" uuid={lesson} title="نقاش الدرس" />
    </div>
  );
}

/**
 * The place a session holds in the sequence, before and after its recording.
 *
 * `unavailable` is the state the spec names (FR-048): the hour has passed and no
 * recording ever arrived. Anything that left this row saying "coming soon" would
 * promise a class that already happened without them, permanently — which is why
 * the server decides the word and this only renders it.
 */
function SessionSlot({ reference }: { reference: ExamReference | SessionReference }) {
  if (!("state" in reference)) return null;

  const when = formatSessionTime(reference.starts_at, reference.timezone);

  if (reference.state === "upcoming") {
    return (
      <Alert tone="info" title="حصة مباشرة قادمة">
        {when} — يصلك تسجيلها في هذا الموضع بعد انتهائها. حضورها يحتاج مقعداً محجوزاً.
      </Alert>
    );
  }

  if (reference.state === "processing") {
    return (
      <Alert tone="info" title="التسجيل قيد التجهيز">
        انتهت الحصة ({when}) وتسجيلها يُجهَّز الآن. عُد بعد قليل.
      </Alert>
    );
  }

  if (reference.state === "cancelled") {
    return (
      <Alert tone="warning" title="أُلغيت هذه الحصة">
        لن يصل تسجيل لها. لا شيء ينتظرك في هذا الموضع.
      </Alert>
    );
  }

  return (
    <Alert tone="warning" title="لا تسجيل لهذه الحصة">
      مضى موعد الحصة ({when}) ولم يُنشر تسجيل لها. اسأل مدرّسك إن كنت تنتظره.
    </Alert>
  );
}
