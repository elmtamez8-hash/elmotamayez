import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { WB } from "@/lib/whiteboard/strings";

const calls = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn() }));
vi.mock("@/lib/whiteboard/api", () => ({ boards: calls }));

import { LessonBoardsSection } from "./LessonBoardsSection";

describe("LessonBoardsSection", () => {
  const tab = { location: { href: "" }, close: vi.fn() };

  beforeEach(() => {
    vi.clearAllMocks();
    tab.location.href = "";
    vi.spyOn(window, "open").mockReturnValue(tab as unknown as Window);
  });
  afterEach(() => vi.restoreAllMocks());

  it("lists the lesson's boards and makes a new one for it, opened in its own tab", async () => {
    calls.list.mockResolvedValue({ data: [{ uuid: "b1", title: "شرح الدرس" }] });
    calls.create.mockResolvedValue({ uuid: "b2", title: "الكسور" });
    render(<LessonBoardsSection lessonUuid="l1" lessonTitle="الكسور" />);

    expect(await screen.findByText("شرح الدرس")).toBeTruthy();
    expect(calls.list).toHaveBeenCalledWith({ lesson: "l1" });

    fireEvent.click(screen.getByRole("button", { name: WB.lessonBoards.create }));

    await waitFor(() => expect(tab.location.href).toBe("/whiteboard/b2"));
    expect(calls.create).toHaveBeenCalledWith({ title: "الكسور", lesson: "l1" });
    expect(screen.getByText("الكسور")).toBeTruthy();
  });

  it("shows nothing to someone the boards door refuses", async () => {
    calls.list.mockRejectedValue(new Error("403"));
    const { container } = render(<LessonBoardsSection lessonUuid="l1" lessonTitle="الكسور" />);
    await waitFor(() => expect(calls.list).toHaveBeenCalled());
    expect(container.textContent).toBe("");
  });

  it("closes the blank tab and says so when the board cannot be made", async () => {
    calls.list.mockResolvedValue({ data: [] });
    calls.create.mockRejectedValue(new Error("422"));
    render(<LessonBoardsSection lessonUuid="l1" lessonTitle="الكسور" />);

    fireEvent.click(await screen.findByRole("button", { name: WB.lessonBoards.create }));

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(tab.close).toHaveBeenCalled();
  });
});
