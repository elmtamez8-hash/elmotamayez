import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { EmbeddedVideo } from "./EmbeddedVideo";

/*
| ٠٣٢ · FR-017 و FR-021.
|
| ⛔ السطرُ والزرُّ **ظاهرانِ دائماً**، لا مشروطَينِ بكشفٍ لا نملكُه. المستضيفُ
| يردُّ ردّاً سليماً ويكتبُ رسالتَه داخلَ إطارِه، والمتصفّحُ يمنعُ القراءةَ عبرَ
| الأصول — فليسَ ثمّةَ حالةٌ «يظهرُ فيها» الزرّ. ادّعاءُ العكسِ كاشفٌ لا وجودَ
| له، ويقرأُ الزائرُ صمتَه دليلاً على أنّ الفيديو سليم.
*/

const post = vi.fn();

vi.mock("@/lib/api", () => ({ api: { post: (path: string) => post(path) } }));

const REPORT = { courseKey: "physics-3", lessonUuid: "l-1" };

beforeEach(() => {
  vi.clearAllMocks();
  post.mockResolvedValue({ message: "شكراً لك. سُجِّلت ملاحظتك." });
});

describe("the embedded video", () => {
  it("says the video is hosted elsewhere, always", () => {
    render(<EmbeddedVideo embedUrl="https://x/embed/1" title="حصّة" report={REPORT} />);

    expect(screen.getByText(/مستضافة خارج المنصّة/)).toBeDefined();
  });

  it("offers the report button from the first paint", () => {
    render(<EmbeddedVideo embedUrl="https://x/embed/1" title="حصّة" report={REPORT} />);

    expect(screen.getByRole("button", { name: "الفيديو لا يعمل" })).toBeDefined();
  });

  it("knocks once on the report door and nowhere else", async () => {
    render(<EmbeddedVideo embedUrl="https://x/embed/1" title="حصّة" report={REPORT} />);

    fireEvent.click(screen.getByRole("button", { name: "الفيديو لا يعمل" }));

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));

    expect(post).toHaveBeenCalledWith("/marketplace/courses/physics-3/lessons/l-1/report");
  });

  it("thanks without promising anything — the same sentence the server sends", async () => {
    render(<EmbeddedVideo embedUrl="https://x/embed/1" title="حصّة" report={REPORT} />);

    fireEvent.click(screen.getByRole("button", { name: "الفيديو لا يعمل" }));

    // ⛔ «أبلغنا المدرّس» كذبةٌ في فرعِ التكرارِ وفرعِ «لا وجود»، والقارئُ لا يعلمُ
    // في أيِّهما هو.
    expect(await screen.findByText("شكراً لك. سُجِّلت ملاحظتك.")).toBeDefined();
    expect(screen.queryByText(/أبلغنا المدرّس/)).toBeNull();
  });

  it("says the same thing when the call fails, because the server never varies either", async () => {
    post.mockRejectedValue(new Error("network"));

    render(<EmbeddedVideo embedUrl="https://x/embed/1" title="حصّة" report={REPORT} />);

    fireEvent.click(screen.getByRole("button", { name: "الفيديو لا يعمل" }));

    expect(await screen.findByText("شكراً لك. سُجِّلت ملاحظتك.")).toBeDefined();
  });

  it("submits nothing when a second tap follows the first", async () => {
    render(<EmbeddedVideo embedUrl="https://x/embed/1" title="حصّة" report={REPORT} />);

    const button = screen.getByRole("button", { name: "الفيديو لا يعمل" });

    fireEvent.click(button);
    fireEvent.click(button);

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
  });

  it("draws no button at all where there is nothing to report to", () => {
    render(<EmbeddedVideo embedUrl="https://x/embed/1" title="حصّة" />);

    expect(screen.queryByRole("button", { name: "الفيديو لا يعمل" })).toBeNull();
    // ⚠️ والسطرُ يبقى: الحصّةُ ما تزالُ مستضافةً خارجَ المنصّة.
    expect(screen.getByText(/مستضافة خارج المنصّة/)).toBeDefined();
  });
});
