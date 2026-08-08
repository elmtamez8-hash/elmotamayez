"use client";

import { MarkdownField } from "./MarkdownField";
import type { LessonDetail } from "@/lib/courses";

/**
 * The editor for a written lesson.
 *
 * An article IS its body, so there is one field and nothing else — no video
 * picker greyed out, no link box that does not apply. That is the whole reason
 * the editors are per type: a single form carrying every field for every type
 * asks the teacher to work out which half is theirs.
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
    <MarkdownField
      id={`article-${lesson.uuid}`}
      label="نصّ المقالة"
      hint="تنسيق Markdown. الوسوم البرمجية تُزال عند العرض، فالصقُ من محرّر خارجي آمن."
      value={content}
      savedHtml={lesson.content_html}
      disabled={disabled}
      onChange={onChange}
    />
  );
}
