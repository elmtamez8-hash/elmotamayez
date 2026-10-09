import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TrialPlayer } from "./TrialPlayer";

/*
| Spec 040 — a course's «حصة تجريبية» for guests. The trial player fetches its
| descriptor in the browser and plays an upload through `VideoPlayerCore`: no
| watermark, and NEVER a grant renewal (a guest has no grant, and the student
| player's renewal loop would stop the video).
*/

const get = vi.fn();
const renew = vi.fn();

vi.mock("@/lib/api", async (original) => ({
  ...(await original<typeof import("@/lib/api")>()),
  api: { get: (...args: unknown[]) => get(...args) },
}));
vi.mock("@/lib/media", async (original) => ({
  ...(await original<typeof import("@/lib/media")>()),
  media: { renew: (...args: unknown[]) => renew(...args) },
}));

const COURSE = { uuid: "c-1", title: "الفيزياء ٢", slug: "physics-2" };

beforeEach(() => vi.clearAllMocks());

async function mount() {
  await act(async () => {
    render(<TrialPlayer courseKey="physics-2" />);
  });
}

describe("TrialPlayer", () => {
  it("asks the course's own trial door, from the browser", async () => {
    get.mockResolvedValue({ data: { uuid: "l-1", title: "الحركة", kind: "embed", course: COURSE, embed_url: "https://www.youtube-nocookie.com/embed/x" } });

    await mount();

    expect(get).toHaveBeenCalledWith("/marketplace/courses/physics-2/trial");
  });

  it("embeds an embedded trial", async () => {
    get.mockResolvedValue({ data: { uuid: "l-1", title: "الحركة", kind: "embed", course: COURSE, embed_url: "https://www.youtube-nocookie.com/embed/x" } });

    await mount();

    expect(document.querySelector("iframe")?.getAttribute("src")).toContain("youtube-nocookie.com/embed/x");
  });

  it("plays an upload through our stream route, with no watermark and no renewal", async () => {
    vi.useFakeTimers();
    get.mockResolvedValue({
      data: {
        uuid: "l-1",
        title: "الحركة",
        kind: "video",
        course: COURSE,
        playback: { manifest_url: "/api/v1/marketplace/courses/physics-2/trial/stream", format: "progressive", reload_after_seconds: 400 },
      },
    });

    await mount();
    await act(async () => {
      vi.advanceTimersByTime(5 * 60_000);
    });

    expect(document.querySelector("video")?.getAttribute("src")).toContain("/marketplace/courses/physics-2/trial/stream");
    expect(renew).not.toHaveBeenCalled();
    expect(screen.queryByText(/••••/)).toBeNull();
    vi.useRealTimers();
  });

  it("says so in words when the trial cannot be read", async () => {
    get.mockRejectedValue(new Error("boom"));

    await mount();

    expect(screen.getByText("تعذّر تشغيل الحصة التجريبية")).toBeDefined();
  });
});
