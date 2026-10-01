"use client";

import { RichMarkdownEditor } from "@/components/ui/RichMarkdownEditor";
import type { LessonDetail } from "@/lib/courses";

/**
 * The editor for a written lesson.
 *
 * An article IS its body, so there is one field and nothing else — no video
 * picker greyed out, no link box that does not apply. That is the whole reason
 * the editors are per type: a single form carrying every field for every type
 * asks the teacher to work out which half is theirs.
 *
 * The body is the blog's rich-text field (`RichMarkdownEditor`, 2026-10-01): it
 * shows the formatting while writing and still stores MARKDOWN, rendered by the
 * same `MarkdownRenderer` (CommonMark core, raw HTML stripped) as before — so a
 * lesson saved here reads exactly as it did on the student's page.
 *
 * ⚠️ `key` IS THE LESSON. The editor reads `value` once, on mount; switching to
 * another lesson must remount it, or the previous lesson's text stays on screen.
 */
export function ArticleEditor({
  lesson,
  content,
  disabled,
  onChange,
}: {
  lesson: LessonDetail;
  content: string;
  disabled?: boolean;
  onChange: (next: string) => void;
}) {
  return (
    <RichMarkdownEditor
      key={lesson.uuid}
      id={`article-${lesson.uuid}`}
      label="نصّ المقالة"
      hint="اكتب ونسّق مباشرة: عناوين، قوائم، اقتباسات، روابط، وأكواد."
      value={content}
      disabled={disabled}
      onChange={onChange}
    />
  );
}
