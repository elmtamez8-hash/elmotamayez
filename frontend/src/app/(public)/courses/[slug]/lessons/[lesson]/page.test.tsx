import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { NotFoundError, type PreviewLesson } from "@/lib/public-api";

/*
| ٠٣٢ · US2 — الزائرُ بلا حساب.
|
| ⚠️ المقياسُ **قائمةُ النداءات**، لا مجرّدُ الرسم. قاعدةٌ وهميّةٌ تقبلُ كلَّ مسارٍ
| تُخرِجُ شاشةً خضراءَ لا تقولُ شيئاً: هذه الصفحةُ يجبُ أن تطرقَ بابَ السوقِ وحدَه
| **ولا تطرقَ `/learn/lessons/…` أبداً** — ذلك البابُ يشترطُ صفَّ تسجيلٍ أو عضويّةَ
| مساحةِ عمل، فزائرٌ عليه ٤٠٤. سابقةُ `dashboard/page.test.tsx`.
*/

const previewLesson = vi.fn();
const notFoundCalled = vi.fn();

vi.mock("next/navigation", () => ({
  notFound: () => {
    notFoundCalled();
    throw new Error("NEXT_NOT_FOUND");
  },
}));

vi.mock("@/lib/public-api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/public-api")>();

  return {
    ...actual,
    publicApi: {
      previewLesson: (courseKey: string, lessonUuid: string) =>
        previewLesson(courseKey, lessonUuid),
    },
  };
});

const LESSON: PreviewLesson = {
  uuid: "9f1c0d2a-0000-4000-8000-000000000001",
  title: "الحصّة التعريفيّة — الحركة في بعد واحد",
  kind: "embed",
  duration_seconds: 1200,
  embed_url: "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
  course: { uuid: "3b7a", title: "الفيزياء ٣", slug: "physics-3" },
};

async function renderPage() {
  const { default: Page } = await import("./page");

  render(
    await Page({
      params: Promise.resolve({ slug: "physics-3", lesson: LESSON.uuid }),
    }),
  );
}

beforeEach(() => {
  vi.resetModules();
  previewLesson.mockReset();
  notFoundCalled.mockReset();
  previewLesson.mockResolvedValue({ data: LESSON });
});

describe("the public preview lesson page", () => {
  it("knocks on the marketplace door alone, and never on the enrolled student's", async () => {
    await renderPage();

    expect(previewLesson).toHaveBeenCalledTimes(1);
    expect(previewLesson).toHaveBeenCalledWith("physics-3", LESSON.uuid);
  });

  it("plays the video inside our page rather than sending the visitor away", async () => {
    await renderPage();

    const frame = screen.getByTitle(LESSON.title);

    expect(frame.tagName).toBe("IFRAME");
    expect(frame.getAttribute("src")).toBe(LESSON.embed_url);
  });

  it("shows the enrolment invitation from the first paint, with no playback event", async () => {
    /*
     * ⛔ FR-013. Waiting for the video to end would need the host's player
     * library on our page, which FR-016 forbids — and it would be wrong anyway:
     * somebody convinced in the third minute never reaches an invitation placed
     * at the twentieth.
     */
    await renderPage();

    expect(screen.getByText("أعجبتك الحصّة؟")).toBeDefined();
    expect(screen.getByRole("link", { name: "سجّل في الكورس" }).getAttribute("href"))
      .toBe("/courses/physics-3");
  });

  it("prints no duration at all when the teacher left it empty", async () => {
    // ⚠️ الخادمُ يُسقِطُ المفتاحَ عندَ الصفر، والصفحةُ لا تخترعُ «٠ دقيقة».
    previewLesson.mockResolvedValue({
      data: { ...LESSON, duration_seconds: undefined },
    });

    await renderPage();

    expect(screen.queryByText(/دقيقة|دقائق|دقيقتان/)).toBeNull();
  });

  it("shows the duration when there is one", async () => {
    await renderPage();

    expect(screen.getByText("٢٠ دقيقة")).toBeDefined();
  });

  it("answers a refusal with the not-found page, never with a reason", async () => {
    // سبعةُ أسبابٍ للرفضِ وجوابٌ واحد؛ وصفحةٌ مميَّزةٌ لأيٍّ منها تُثبِتُ وجودَ
    // ما يُخفيه الرفضُ نفسُه.
    previewLesson.mockRejectedValue(new NotFoundError("Not found"));

    await expect(renderPage()).rejects.toThrow("NEXT_NOT_FOUND");
    expect(notFoundCalled).toHaveBeenCalled();
  });
});
