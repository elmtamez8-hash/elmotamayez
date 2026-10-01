"use client";

import { Alert } from "@/components/ui/Alert";
import { RichMarkdownEditor } from "@/components/ui/RichMarkdownEditor";
import type { LessonDetail } from "@/lib/courses";

/**
 * The editor for a notice.
 *
 * The banner is the point of this component. A notice looks exactly like an
 * article in the tree, and a teacher who assumes it behaves like one will write
 * "لا تنسَ الامتحان يوم الأحد" and expect it to be read — or worse, expect it to
 * hold the class in place until it is.
 *
 * It does neither, by design: nothing is asked of the reader, so nothing can be
 * marked done. It stays out of the percentage and never blocks what follows it,
 * because a course that stalls on an unopened notice is a course nobody finishes.
 */
export function NoteEditor({
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
    <div className="space-y-3">
      <Alert tone="info" title="التنويه لا يُحتسب ولا يحجب">
        يظهر لطلابك في مكانه من الشجرة، ولا يُطلب منهم فتحه: لا يدخل في نسبة تقدّمهم، ولا يمنع
        وصولهم إلى ما بعده. اجعله للتذكير لا للمحتوى الذي تريد أن يُدرَس.
      </Alert>

      {/* The shared rich-text field, as the article's: Markdown in and out, and
          keyed by the lesson because it reads `value` on mount only. */}
      <RichMarkdownEditor
        key={lesson.uuid}
        id={`note-${lesson.uuid}`}
        label="نصّ التنويه"
        hint="اجعله قصيراً — التنويه الطويل يُقرأ كدرس."
        value={content}
        disabled={disabled}
        onChange={onChange}
      />
    </div>
  );
}
