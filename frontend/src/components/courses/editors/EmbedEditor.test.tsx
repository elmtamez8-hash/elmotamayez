import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { EmbedEditor } from "./EmbedEditor";
import type { LessonDetail } from "@/lib/courses";

/*
| ٠٣٢ · FR-017 و FR-018.
|
| التنبيهُ ليسَ إخلاءَ مسؤوليّة: لا سبيلَ لصفحتِنا أن تعلمَ أنّ الفيديو حُذِف —
| المستضيفُ يردُّ ردّاً سليماً ويكتبُ رسالتَه داخلَ إطارِه، والمتصفّحُ يمنعُ
| القراءةَ عبرَ الأصول. فالمدرّسُ يُخبَرُ **مرّةً واحدةً هنا**، في الموضعِ الذي
| يقفُ فيه حينَ يتّخذُ القرار.
*/
const LESSON = { uuid: "l-1" } as LessonDetail;

describe("the embedded lesson editor", () => {
  it("warns permanently that the video is not ours and that we cannot see it break", () => {
    render(
      <EmbedEditor
        lesson={LESSON}
        url=""
        durationSeconds=""
        onUrlChange={() => {}}
        onDurationChange={() => {}}
      />,
    );

    expect(screen.getByText(/الفيديو عند يوتيوب أو فيميو، لا عندنا/)).toBeDefined();
    expect(screen.getByText(/ولا نعلم بذلك/)).toBeDefined();
  });

  it("offers a duration field at all — the one type whose duration the teacher writes", () => {
    render(
      <EmbedEditor
        lesson={LESSON}
        url=""
        durationSeconds=""
        onUrlChange={() => {}}
        onDurationChange={() => {}}
      />,
    );

    // ⚠️ `LessonEdit` لم يكنْ يحملُ `duration_seconds` إطلاقاً ولا محرِّرَ واحدٌ
    // يرسمُ الحقل، فـFR-018 بلا هذا سطحٌ غيرُ موجود.
    expect(screen.getByLabelText(/المدّة بالثواني/)).toBeDefined();
  });

  it("shows an empty box rather than a zero when no duration was written", () => {
    render(
      <EmbedEditor
        lesson={LESSON}
        url=""
        durationSeconds=""
        onUrlChange={() => {}}
        onDurationChange={() => {}}
      />,
    );

    // ⛔ «٠ دقيقة» كذبةٌ لا فراغ: القيمةُ الافتراضيّةُ في القاعدةِ صفر.
    expect((screen.getByLabelText(/المدّة بالثواني/) as HTMLInputElement).value).toBe("");
    expect(screen.queryByText("0")).toBeNull();
  });

  it("names the cost of leaving the duration empty, which nobody would guess", () => {
    render(
      <EmbedEditor
        lesson={LESSON}
        url=""
        durationSeconds=""
        onUrlChange={() => {}}
        onDurationChange={() => {}}
      />,
    );

    // مجموعُ المُدَدِ يُبنى منه طولُ الكورسِ المعلَنُ على صفحتِه العامّة، ولا
    // صيغةَ «غائب» لمجموع.
    expect(screen.getByText(/مدّة الكورس المعلنة تنقص/)).toBeDefined();
  });
});
