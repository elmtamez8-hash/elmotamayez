"use client";

import { Alert } from "@/components/ui/Alert";
import { NumberField, TextField } from "@/components/ui/Field";
import type { LessonDetail } from "@/lib/courses";

/**
 * The editor for a lesson hosted at YouTube or Vimeo (032 · US1).
 *
 * ⚠️ THE PERMANENT WARNING IS THE FEATURE, NOT A DISCLAIMER (FR-017). The
 * platform CANNOT detect that the video was deleted or made private: the host
 * answers a perfectly valid response and writes its message inside its own
 * frame, and the browser forbids reading across origins. So the teacher is told
 * once, here, in the one place they will be standing when they make the
 * decision — and the viewer's report button is the only sensor there is.
 *
 * Unlike its sibling {@link LinkEditor}, this type IS counted and IS completable:
 * the video plays inside our page rather than sending the student somewhere
 * else, so it is the uploaded video with a different host behind the frame.
 */
export function EmbedEditor({
  lesson,
  url,
  durationSeconds,
  disabled,
  onUrlChange,
  onDurationChange,
}: {
  lesson: LessonDetail;
  url: string;
  durationSeconds: string;
  disabled?: boolean;
  onUrlChange: (next: string) => void;
  onDurationChange: (next: string) => void;
}) {
  return (
    <div className="space-y-3">
      <Alert tone="warning" title="الفيديو عند يوتيوب أو فيميو، لا عندنا">
        هذا الدرس يعرض فيديو تستضيفه أنت على قناتك. إن حذفته أو جعلته خاصّاً انكسرت الحصّة{" "}
        <strong>ولا نعلم بذلك</strong> — لا سبيل لصفحتنا أن ترى ما يجري داخل إطار موقع آخر. من
        يشاهدها يجد زرّ إبلاغ تحت الفيديو، وضغطه هو ما يصلك.
      </Alert>

      <TextField
        id={`embed-url-${lesson.uuid}`}
        label="رابط الفيديو"
        type="url"
        hint="من يوتيوب أو فيميو فقط. نبني نحن إطار العرض من الرابط، فلا يظهر ما تلصقه كما هو."
        placeholder="https://youtu.be/…"
        value={url}
        disabled={disabled}
        onChange={onUrlChange}
      />

      <NumberField
        id={`embed-duration-${lesson.uuid}`}
        label="المدّة بالثواني"
        min={0}
        step={1}
        /*
         * ⚠️ THE HINT NAMES A COST NOBODY WOULD GUESS. `CourseDuration::recompute`
         * sums this column across the countable items, and a sum has no way to
         * say «absent» the way one key does — so a duration left empty makes the
         * course look SHORTER than it is on its own public page.
         */
        hint="نكتبها بأنفسنا — المستضيف لا يعطينا المدّة. واتركها فارغة فلا يُعرض رقم، لكنّ مدّة الكورس المعلنة تنقص بقدرها."
        placeholder="مثال: 1200"
        value={durationSeconds}
        disabled={disabled}
        onChange={onDurationChange}
      />
    </div>
  );
}
