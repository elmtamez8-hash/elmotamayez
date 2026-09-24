import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import AnnouncementsPage from "./page";

/*
| Editing an urgent announcement keeps it urgent, and the course picker is not
| cut off at the first page.
|
| The edit form was given `{ body, scope }` and nothing else, so it started with
| «عاجل» unticked (and disabled, since the scope is locked) — every correction
| to an urgent notice was saved as a routine one. And `/courses` pages at 15, so
| the sixteenth course could never be addressed.
*/

const get = vi.fn();
const list = vi.fn();
const update = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}));

vi.mock("@/lib/announcements", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/announcements")>()),
  announcements: {
    list: () => list(),
    create: vi.fn(),
    publish: vi.fn(),
    update: (...args: unknown[]) => update(...args),
    hide: vi.fn(),
  },
}));

vi.mock("@/lib/class-sessions", () => ({
  classSessions: { list: () => Promise.resolve({ data: [] }) },
}));

beforeEach(() => {
  vi.clearAllMocks();
  get.mockResolvedValue({ data: [] });
  update.mockResolvedValue({});
  list.mockResolvedValue({
    data: [
      {
        uuid: "an-1",
        body: "الحصة مؤجلة",
        scope: "all",
        is_urgent: true,
        is_published: true,
        is_hidden: false,
        published_at: null,
        created_at: null,
        notified_count: 3,
        read_count: 1,
      },
    ],
  });
});

describe("announcements page", () => {
  it("asks for every course, not the first page of fifteen", async () => {
    await act(async () => {
      render(<AnnouncementsPage />);
    });

    expect(get).toHaveBeenCalledWith("/courses?per_page=200");
  });

  it("keeps an urgent announcement urgent when its text is corrected", async () => {
    await act(async () => {
      render(<AnnouncementsPage />);
    });

    fireEvent.click(await screen.findByRole("button", { name: "تعديل النصّ" }));

    const saves = screen.getAllByRole("button", { name: "حفظ" });

    await act(async () => {
      fireEvent.click(saves[saves.length - 1]);
    });

    expect(update).toHaveBeenCalledWith(
      "an-1",
      expect.objectContaining({ body: "الحصة مؤجلة", is_urgent: true }),
    );
  });
});
