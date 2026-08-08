"use client";

import { use, useCallback, useEffect, useState } from "react";
import { LessonEditor } from "@/components/courses/LessonEditor";
import { TreeOutline } from "@/components/courses/TreeOutline";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage } from "@/lib/api";
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
        setError(errorMessage(err, "تعذّر حفظ التغيير. أعد المحاولة."));
        // Re-read even on failure: a 409 means the tree moved under us, and the
        // screen must show what is actually there before the next attempt.
        await courses.tree(uuid).then(setTree).catch(() => undefined);
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

  /** Every node still hidden from students, in publish order: section, then its
      chapters, then their items — so nothing lands published-but-blocked. */
  const drafts: PublishItem[] = tree.sections.flatMap((section) => [
    ...(section.status === "published" ? [] : [{ uuid: section.uuid, status: "published" as const }]),
    ...section.chapters.flatMap((chapter) => [
      ...(chapter.status === "published" ? [] : [{ uuid: chapter.uuid, status: "published" as const }]),
      ...chapter.lessons
        .filter((lesson) => lesson.status !== "published")
        .map((lesson) => ({ uuid: lesson.uuid, status: "published" as const })),
    ]),
  ]);

  const publish = (items: PublishItem[], message: string) =>
    void run(() => courses.publishTree(uuid, tree.structure_version, items), message);

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
          */}
          {drafts.length > 0 && (
            <Button
              disabled={busy}
              onClick={() =>
                publish(drafts, `نُشر ${drafts.length} عنصراً — صارت مرئية لطلابك الآن.`)
              }
            >
              نشر كل المسودّات ({drafts.length})
            </Button>
          )}

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
          publish(
            [{ uuid: nodeUuid, status }],
            status === "published"
              ? `نُشر «${label}» — صار مرئياً لطلابك الآن.`
              : `أُلغي نشر «${label}» — لم يعد يظهر لطلابك.`,
          )
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
          if (!window.confirm(`سيُحذف «${label}» وكل ما بداخله. متابعة؟`)) return;

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
