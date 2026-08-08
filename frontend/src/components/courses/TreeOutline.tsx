"use client";

import { useState } from "react";
import { MoveControls } from "./MoveControls";
import { StatusBadge } from "./StatusBadge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TrashIcon } from "@/components/icons";
import type { ContentStatus, CourseTree, TreeChapter, TreeSection } from "@/lib/courses";

/**
 * The course tree as its author sees it: drafts included, with the reason each
 * node is hidden.
 *
 * Indentation uses `ms-*`, never `ml-*` — the tree grows from the right.
 */
export function TreeOutline({
  tree,
  busy,
  onAddSection,
  onAddChapter,
  onAddLesson,
  onRename,
  onDelete,
  onMoveSection,
  onMoveChapter,
  onMoveLesson,
  onEditLesson,
  onSetStatus,
}: {
  tree: CourseTree;
  busy: boolean;
  onAddSection: (title: string) => void;
  onAddChapter: (sectionUuid: string, title: string) => void;
  onAddLesson: (chapter: TreeChapter) => void;
  onRename: (kind: "section" | "chapter" | "lesson", uuid: string, title: string) => void;
  onDelete: (kind: "section" | "chapter" | "lesson", uuid: string, title: string) => void;
  onMoveSection: (uuid: string, direction: -1 | 1) => void;
  onMoveChapter: (section: TreeSection, uuid: string, direction: -1 | 1) => void;
  onMoveLesson: (chapter: TreeChapter, uuid: string, direction: -1 | 1) => void;
  onEditLesson: (uuid: string) => void;
  onSetStatus: (uuid: string, status: ContentStatus, label: string) => void;
}) {
  const [newSectionTitle, setNewSectionTitle] = useState("");

  return (
    <div className="space-y-4">
      {tree.sections.map((section, sectionIndex) => (
        <Card key={section.uuid} as="section">
          <header className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-ink">{section.title}</h3>
              <StatusBadge status={section.status} />
            </div>
            <div className="flex items-center gap-1">
              <MoveControls
                label={section.title}
                canMoveUp={sectionIndex > 0}
                canMoveDown={sectionIndex < tree.sections.length - 1}
                onMove={(direction) => onMoveSection(section.uuid, direction)}
                busy={busy}
              />
              <StatusButton node={section} busy={busy} onSetStatus={onSetStatus} />
              <RenameButton
                onRename={(title) => onRename("section", section.uuid, title)}
                current={section.title}
                busy={busy}
              />
              <DeleteButton
                onDelete={() => onDelete("section", section.uuid, section.title)}
                label={section.title}
                busy={busy}
              />
            </div>
          </header>

          <div className="mt-4 space-y-3">
            {section.chapters.map((chapter, chapterIndex) => (
              <div key={chapter.uuid} className="ms-4 border-s border-line ps-4">
                <header className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <h4 className="text-sm font-medium text-ink">{chapter.title}</h4>
                    <StatusBadge status={chapter.status} blockedBy={chapter.blocked_by} />
                  </div>
                  <div className="flex items-center gap-1">
                    <MoveControls
                      label={chapter.title}
                      canMoveUp={chapterIndex > 0}
                      canMoveDown={chapterIndex < section.chapters.length - 1}
                      onMove={(direction) => onMoveChapter(section, chapter.uuid, direction)}
                      busy={busy}
                    />
                    <StatusButton node={chapter} busy={busy} onSetStatus={onSetStatus} />
                    <RenameButton
                      onRename={(title) => onRename("chapter", chapter.uuid, title)}
                      current={chapter.title}
                      busy={busy}
                    />
                    <DeleteButton
                      onDelete={() => onDelete("chapter", chapter.uuid, chapter.title)}
                      label={chapter.title}
                      busy={busy}
                    />
                  </div>
                </header>

                <ul className="mt-2 space-y-1">
                  {chapter.lessons.map((lesson, lessonIndex) => (
                    <li
                      key={lesson.uuid}
                      className="flex flex-wrap items-center justify-between gap-2 rounded px-2 py-1 hover:bg-surface-raised"
                    >
                      <span className="flex flex-wrap items-center gap-2 text-sm text-ink">
                        <span className="text-xs text-ink-muted">{lesson.type_label}</span>
                        {lesson.title}
                        <StatusBadge status={lesson.status} blockedBy={lesson.blocked_by} />
                        {/* Said out loud: a recording is watched by whoever held
                            a seat in that session, not by whoever enrolled. */}
                        {lesson.is_recording ? (
                          <span className="text-xs text-ink-muted">تسجيل حصة — يُشاهده أصحاب المقاعد</span>
                        ) : null}
                      </span>
                      <span className="flex items-center gap-1">
                        <MoveControls
                          label={lesson.title}
                          canMoveUp={lessonIndex > 0}
                          canMoveDown={lessonIndex < chapter.lessons.length - 1}
                          onMove={(direction) => onMoveLesson(chapter, lesson.uuid, direction)}
                          busy={busy}
                        />
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={busy}
                          onClick={() => onEditLesson(lesson.uuid)}
                        >
                          تحرير
                        </Button>
                        <StatusButton node={lesson} busy={busy} onSetStatus={onSetStatus} />
                        <RenameButton
                          onRename={(title) => onRename("lesson", lesson.uuid, title)}
                          current={lesson.title}
                          busy={busy}
                        />
                        <DeleteButton
                          onDelete={() => onDelete("lesson", lesson.uuid, lesson.title)}
                          label={lesson.title}
                          busy={busy}
                        />
                      </span>
                    </li>
                  ))}
                </ul>

                <div className="mt-2">
                  <Button size="sm" variant="ghost" onClick={() => onAddLesson(chapter)}>
                    + إضافة عنصر
                  </Button>
                </div>
              </div>
            ))}

            <div className="ms-4">
              <InlineAdd
                placeholder="عنوان الفصل الجديد"
                cta="إضافة فصل"
                busy={busy}
                onSubmit={(title) => onAddChapter(section.uuid, title)}
              />
            </div>
          </div>
        </Card>
      ))}

      <Card>
        <InlineAdd
          placeholder="عنوان القسم الجديد"
          cta="إضافة قسم"
          busy={busy}
          value={newSectionTitle}
          onValueChange={setNewSectionTitle}
          onSubmit={(title) => {
            onAddSection(title);
            setNewSectionTitle("");
          }}
        />
      </Card>
    </div>
  );
}

/**
 * Publish or unpublish one node.
 *
 * Publishing is what makes a node exist for a student — it enters their
 * percentage and, in a sequential course, the gate. Unpublishing is offered on
 * the same button because the reverse has to be one click away: a teacher who
 * published something half-finished should not have to delete it.
 */
function StatusButton({
  node,
  busy,
  onSetStatus,
}: {
  node: { uuid: string; title: string; status: ContentStatus };
  busy: boolean;
  onSetStatus: (uuid: string, status: ContentStatus, label: string) => void;
}) {
  const publishing = node.status !== "published";

  return (
    <Button
      size="sm"
      variant={publishing ? "secondary" : "ghost"}
      disabled={busy}
      onClick={() => onSetStatus(node.uuid, publishing ? "published" : "draft", node.title)}
    >
      {publishing ? "نشر" : "إلغاء النشر"}
    </Button>
  );
}

function InlineAdd({
  placeholder,
  cta,
  busy,
  value,
  onValueChange,
  onSubmit,
}: {
  placeholder: string;
  cta: string;
  busy: boolean;
  value?: string;
  onValueChange?: (value: string) => void;
  onSubmit: (title: string) => void;
}) {
  const [internal, setInternal] = useState("");
  const current = value ?? internal;
  const setCurrent = onValueChange ?? setInternal;

  return (
    <form
      className="flex flex-wrap items-center gap-2"
      onSubmit={(event) => {
        event.preventDefault();
        if (current.trim() === "") return;
        onSubmit(current.trim());
        setCurrent("");
      }}
    >
      <input
        type="text"
        value={current}
        onChange={(event) => setCurrent(event.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        className="min-w-0 flex-1 rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:border-primary focus:outline-none"
      />
      <Button type="submit" size="sm" variant="secondary" disabled={busy || current.trim() === ""}>
        {cta}
      </Button>
    </form>
  );
}

function RenameButton({
  current,
  onRename,
  busy,
}: {
  current: string;
  onRename: (title: string) => void;
  busy: boolean;
}) {
  const [editing, setEditing] = useState(false);
  const [title, setTitle] = useState(current);

  if (!editing) {
    return (
      <Button size="sm" variant="ghost" disabled={busy} onClick={() => { setTitle(current); setEditing(true); }}>
        تسمية
      </Button>
    );
  }

  return (
    <form
      className="flex items-center gap-1"
      onSubmit={(event) => {
        event.preventDefault();
        if (title.trim() !== "") onRename(title.trim());
        setEditing(false);
      }}
    >
      <input
        type="text"
        value={title}
        autoFocus
        onChange={(event) => setTitle(event.target.value)}
        aria-label={`الاسم الجديد لـ «${current}»`}
        className="w-40 rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink focus:border-primary focus:outline-none"
      />
      <Button type="submit" size="sm" variant="secondary">
        حفظ
      </Button>
    </form>
  );
}

function DeleteButton({
  label,
  onDelete,
  busy,
}: {
  label: string;
  onDelete: () => void;
  busy: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onDelete}
      disabled={busy}
      aria-label={`حذف «${label}»`}
      className="rounded p-1 text-ink-muted transition hover:bg-danger/10 hover:text-danger disabled:opacity-40"
    >
      <TrashIcon className="size-4" />
    </button>
  );
}
