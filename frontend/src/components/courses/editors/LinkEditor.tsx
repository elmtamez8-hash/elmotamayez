"use client";

import { Alert } from "@/components/ui/Alert";
import { TextField } from "@/components/ui/Field";
import type { LessonDetail } from "@/lib/courses";

/**
 * The editor for an external link.
 *
 * The warning is not a disclaimer — it is the honest description of what this
 * type does. Everything spec 004 built to protect a teacher's material applies
 * to files on this platform and to nothing else: no watermark carrying the
 * viewer's name, no grant that expires, no device limit, no record that the
 * student opened it. A link is a link.
 *
 * It also cannot be completed, for a reason worth stating rather than hiding:
 * the platform has no way to know what someone did on another website, so a
 * "finished" button would be a button pressed by people who never opened it —
 * and, in a sequential course, a gate opened by pressing it.
 */
export function LinkEditor({
  lesson,
  url,
  disabled,
  onChange,
}: {
  lesson: LessonDetail;
  url: string;
  disabled?: boolean;
  onChange: (next: string) => void;
}) {
  return (
    <div className="space-y-3">
      <Alert tone="warning" title="المحتوى الخارجي خارج حماية المنصّة كلياً">
        ما تضعه هنا يُفتح على موقع آخر: بلا علامة مائية باسم الطالب، وبلا رابط ينتهي، وبلا حدّ
        للأجهزة، وبلا أي علم لنا بمن فتحه. ولا يُحتسب في نسبة التقدّم ولا يحجب ما بعده — لا نملك
        وسيلة لمعرفة أن الطالب أنهاه. ارفع المادة التي تريد حمايتها بدل ربطها.
      </Alert>

      <TextField
        id={`link-${lesson.uuid}`}
        label="الرابط"
        type="url"
        hint="يجب أن يبدأ بـ https:// — الروابط غير المشفّرة مرفوضة."
        placeholder="https://"
        value={url}
        disabled={disabled}
        onChange={onChange}
      />
    </div>
  );
}
