"use client";

import { use, useCallback, useEffect, useState } from "react";
import { LessonEditor } from "@/components/courses/LessonEditor";
import { PublishImpactDialog } from "@/components/courses/PublishImpactDialog";
import { TreeOutline } from "@/components/courses/TreeOutline";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ApiError, errorMessage } from "@/lib/api";
import {
  courses,
  moveWithin,
  type ContentStatus,
  type CourseTree,
  type LessonTypeValue,
  type PublishItem,
  type TreeChapter,
  type TreeSection,
} from "@/lib/courses";

/**
 * A status change waiting on the teacher, once they have seen what it costs.
 *
 * `items` absent means "every draft in this course" — the server derives that
 * set and returns it with the impact, so this screen never assembles its own.
 */
interface PendingPublish {
  items?: PublishItem[];
  title: string;
  confirmLabel: string;
  message: string;
}

/** The 409 body this screen knows how to act on: our own, carrying the tree. */
function isTreeConflict(body: unknown): body is { message: string; tree: CourseTree } {
  return (
    typeof body === "object" &&
    body !== null &&
    "tree" in body &&
    typeof (body as { message?: unknown }).message === "string"
  );
}

/**
 * The course authoring surface.
 *
 * Ten structure endpoints have existed since the first migration and nothing in
 * the product called any of them — a teacher could not create a section, a
 * chapter or a lesson from any screen. This is that screen.
 *
 * Two rules govern everything on it:
 *
 * **Reordering is a write to access rights.** In a sequential course the gate
 * reads the stored positions, so moving a lesson changes who can reach what.
 * Every move therefore sends the complete sibling list and the version token,
 * and the tree is re-read afterwards rather than patched locally.
 *
 * **A new node is a draft.** Nothing created here is visible to a student, or
 * counted in their percentage, until it is published — which is what makes
 * authoring a course thirty people are enrolled in safe at all.
 */
export default function CourseContentPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);


  const [tree, setTree] = useState<CourseTree | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState<string | null>(null);
  const [pending, setPending] = useState<PendingPublish | null>(null);

  /*
    `?lesson=` opens that item straight away.

    The course overview links each item here by uuid, so the link has to say WHICH
    one — otherwise "edit this lesson" lands on a tree and asks the teacher to find
    again the row they just clicked.

    ⚠️ READ FROM `location` IN AN EFFECT, NOT WITH `useSearchParams`. That hook
    opts the page out of static prerendering unless it sits inside a `<Suspense>`
    boundary, and the build FAILS on it rather than warning — a page split in two
    to carry one optional query parameter. Once on mount, because this decides
    where to start rather than staying in charge: re-selecting on every URL change
    would reopen the editor a teacher had just closed.
  */
  useEffect(() => {
    const lesson = new URLSearchParams(window.location.search).get("lesson");

    if (lesson !== null && lesson !== "") setEditing(lesson);
  }, []);

  const load = useCallback(() => {
    setLoading(true);
    setError("");

    courses
      .tree(uuid)
      .then(setTree)
      .catch((err: unknown) => setError(errorMessage(err, "تعذّر تحميل محتوى الكورس. أعد المحاولة.")))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(load, [load]);

  /**
   * Every write goes through here.
   *
   * It re-reads the tree instead of patching state locally, and that is
   * deliberate: positions, the version token and the "blocked by its section"
   * reason are all computed on the server, and a local guess at any of them
   * would be a second implementation of the rule.
   */
  const run = useCallback(
    async (work: () => Promise<unknown>, successMessage?: string) => {
      setBusy(true);
      setNotice("");
      setError("");

      try {
        await work();
        const fresh = await courses.tree(uuid);
        setTree(fresh);
        if (successMessage) setNotice(successMessage);
      } catch (err: unknown) {
        // A 409 answers with the tree as it actually is, so the map is redrawn
        // from the refusal itself. Fetching it again instead would read a moment
        // later than the one that refused us — and could already be stale.
        const conflict =
          err instanceof ApiError && err.status === 409 && isTreeConflict(err.body)
            ? err.body
            : null;

        if (conflict !== null) {
          setTree(conflict.tree);
          // The server's own sentence, not the table's. `errors.ts` maps 409 to
          // "حدّث الصفحة وأعد المحاولة" — which was right until this screen
          // started redrawing itself from the refusal, and is now an instruction
          // to do something that has already happened. The rule that bans raw
          // errors bans FRAMEWORK strings; this one is ours and is in Arabic.
          setError(conflict.message);
        } else {
          setError(errorMessage(err, "تعذّر حفظ التغيير. أعد المحاولة."));
          await courses.tree(uuid).then(setTree).catch(() => undefined);
        }
      } finally {
        setBusy(false);
      }
    },
    [uuid],
  );

  if (loading) return <RowsSkeleton />;

  if (error !== "" && tree === null) {
    return <ErrorState title="تعذّر تحميل محتوى الكورس" description={error} onRetry={load} />;
  }

  if (tree === null) return <RowsSkeleton />;

  /**
   * Nothing here changes state directly — every status change opens the impact
   * dialog first (`FR-049`).
   *
   * That includes a single node. Publishing one item still adds it to thirty
   * students' denominator and, in a sequential course, still puts it in front of
   * whatever follows it; a confirmation for the batch and a silent toggle for one
   * node would be drawing the line at the size of the click rather than at the
   * size of the consequence.
   *
   * The set for "publish everything" is deliberately NOT computed here. The
   * server derives it and returns it with the impact, so what is costed and what
   * is sent cannot be two different lists.
   */
  const confirmPublish = (pending: PendingPublish) => {
    setError("");
    setNotice("");
    setPending(pending);
  };

  const addLesson = (chapter: TreeChapter) => {
    const title = window.prompt("عنوان العنصر الجديد");
    if (title === null || title.trim() === "") return;

    // Article is the default because it is the only type that needs nothing
    // uploaded or referenced — the teacher can start writing immediately and
    // change the type from the item itself.
    const type: LessonTypeValue = "article";

    void run(
      () => courses.createLesson(uuid, { chapter_uuid: chapter.uuid, title: title.trim(), type }),
      "أُضيف العنصر كمسودّة — لن يراه طلابك حتى تنشره.",
    );
  };

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          {/* h2, not h1: the shell header already renders the page title. */}
          <h2 className="text-2xl font-bold text-ink">محتوى الكورس</h2>
          <p className="mt-1 text-sm text-ink-muted">
            {tree.title} — كل ما تضيفه يبدأ مسودّة، فلا يظهر لطلابك ولا يغيّر نسبة تقدّمهم حتى
            تنشره.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {/*
            One request for the whole batch, not one per node. A section and its
            items become visible together; publishing them in eleven separate
            calls shows a student eleven different half-built trees on the way.

            No `items`: the server works out what is still a draft and answers
            with that list, which is then what gets published.
          */}
          <Button
            disabled={busy}
            onClick={() =>
              confirmPublish({
                title: "نشر كل المسودّات",
                confirmLabel: "انشر الآن",
                message: "نُشرت المسودّات — صارت مرئية لطلابك الآن.",
              })
            }
          >
            نشر كل المسودّات
          </Button>

          <Button href={`/manage/courses/${uuid}`} variant="secondary">
            العودة إلى الكورس
          </Button>
        </div>
      </header>

      {tree.is_sequential ? (
        <Alert tone="info" title="هذا الكورس متسلسل">
          ترتيب العناصر هنا يحدّد ما يُفتح لطلابك: لا يصل الطالب إلى عنصر قبل أن يُتمّ ما قبله.
          فتحريك عنصر ليس تغييراً في العرض وحده.
        </Alert>
      ) : null}

      {error !== "" && (
        <Alert tone="danger" title="تعذّر حفظ التغيير">
          {error}
        </Alert>
      )}

      {notice !== "" && (
        <Alert tone="info" title="تمّ الحفظ">
          {notice}
        </Alert>
      )}

      {pending !== null && (
        <PublishImpactDialog
          courseUuid={uuid}
          items={pending.items}
          title={pending.title}
          confirmLabel={pending.confirmLabel}
          onClose={() => setPending(null)}
          onConfirm={(preview) => {
            setPending(null);
            // The version comes from the preview too: it is the one the impact
            // was computed against, so a tree that moved in between 409s instead
            // of publishing numbers nobody was shown.
            void run(
              () => courses.publishTree(uuid, preview.structure_version, preview.items),
              pending.message,
            );
          }}
        />
      )}

      {editing !== null && (
        <LessonEditor
          courseUuid={uuid}
          lessonUuid={editing}
          onSaved={() => void run(async () => undefined)}
          onClose={() => setEditing(null)}
        />
      )}

      <TreeOutline
        tree={tree}
        busy={busy}
        onEditLesson={setEditing}
        onSetStatus={(nodeUuid, status: ContentStatus, label) =>
          confirmPublish({
            items: [{ uuid: nodeUuid, status }],
            title:
              status === "published" ? `نشر «${label}»` : `إخفاء «${label}» عن الطلاب`,
            confirmLabel: status === "published" ? "انشر" : "نفّذ",
            message:
              status === "published"
                ? `نُشر «${label}» — صار مرئياً لطلابك الآن.`
                : `أُلغي نشر «${label}» — لم يعد يظهر لطلابك.`,
          })
        }
        onAddSection={(title) =>
          void run(() => courses.createSection(uuid, title), "أُضيف القسم كمسودّة.")
        }
        onAddChapter={(sectionUuid, title) =>
          void run(() => courses.createChapter(uuid, sectionUuid, title), "أُضيف الفصل كمسودّة.")
        }
        onAddLesson={addLesson}
        onRename={(kind, nodeUuid, title) =>
          void run(() => {
            if (kind === "section") return courses.renameSection(uuid, nodeUuid, title);
            if (kind === "chapter") return courses.renameChapter(uuid, nodeUuid, title);
            return courses.renameLesson(uuid, nodeUuid, title);
          })
        }
        onDelete={(kind, nodeUuid, label) => {
          // Confirmed here, and refused on the server when it would destroy
          // recorded progress or an uploaded file — the dialog is a courtesy,
          // the guard is the Action.
          //
          // A recording gets its own sentence (FR-053). Deleting one is not
          // "removing an item from a course": it is the only route anyone who
          // attended that session has back to it, and the row is what carries the
          // entitlement — nothing else in the product links them to it.
          const recording = tree.sections.some((section) =>
            section.chapters.some((chapter) =>
              chapter.lessons.some((lesson) => lesson.uuid === nodeUuid && lesson.is_recording),
            ),
          );

          const question = recording
            ? `«${label}» تسجيل حصة، وهو الطريق الوحيد لمن حضرها إليه. حذفه يقطعه عنهم نهائياً. متابعة؟`
            : `سيُحذف «${label}» وكل ما بداخله. متابعة؟`;

          if (!window.confirm(question)) return;

          void run(() => {
            if (kind === "section") return courses.deleteSection(uuid, nodeUuid);
            if (kind === "chapter") return courses.deleteChapter(uuid, nodeUuid);
            return courses.deleteLesson(uuid, nodeUuid);
          }, "حُذف العنصر.");
        }}
        onMoveSection={(nodeUuid, direction) => {
          const order = moveWithin(
            tree.sections.map((section: TreeSection) => section.uuid),
            nodeUuid,
            direction,
          );

          void run(() => courses.reorderSections(uuid, tree.structure_version, order));
        }}
        onMoveChapter={(section, nodeUuid, direction) => {
          const order = moveWithin(
            section.chapters.map((chapter) => chapter.uuid),
            nodeUuid,
            direction,
          );

          void run(() =>
            courses.reorderChapters(uuid, section.uuid, tree.structure_version, order),
          );
        }}
        onMoveLesson={(chapter, nodeUuid, direction) => {
          const order = moveWithin(
            chapter.lessons.map((lesson) => lesson.uuid),
            nodeUuid,
            direction,
          );

          void run(() =>
            courses.reorderLessons(uuid, chapter.uuid, tree.structure_version, order),
          );
        }}
      />
    </div>
  );
}
