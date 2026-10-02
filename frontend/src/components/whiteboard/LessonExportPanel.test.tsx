import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import type { BoardDetail } from "@/lib/whiteboard/api";
import { WB } from "@/lib/whiteboard/strings";

const calls = vi.hoisted(() => ({
  record: vi.fn(),
  replace: vi.fn(),
  requestUpload: vi.fn(),
  uploadTo: vi.fn(),
  complete: vi.fn(),
}));

vi.mock("@/lib/api", async (original) => ({
  ...(await original<typeof import("@/lib/api")>()),
  api: { get: vi.fn(async () => ({ data: [{ uuid: "c1", title: "فيزياء" }] })) },
  uploadToTicket: calls.uploadTo,
}));
vi.mock("@/lib/courses", () => ({
  courses: { tree: vi.fn(async () => ({ sections: [{ chapters: [{ lessons: [{ uuid: "l1", title: "الحركة" }] }] }] })) },
}));
vi.mock("@/lib/media", () => ({
  media: { requestUpload: calls.requestUpload, complete: calls.complete },
}));
vi.mock("@/lib/whiteboard/api", () => ({
  boards: { recordExport: calls.record, replaceExport: calls.replace },
}));

import { LessonExportPanel } from "./LessonExportPanel";

function board(exports: BoardDetail["exports"]): BoardDetail {
  return {
    uuid: "b1",
    title: "سبّورة",
    course: { uuid: "c1", title: "فيزياء", deleted: false },
    lesson: { uuid: "l1", title: "الحركة" },
    pages: [],
    exports,
  } as unknown as BoardDetail;
}

const renderPdf = vi.fn(async (onPage: (done: number) => void) => {
  onPage(1);
  return new Blob(["%PDF"], { type: "application/pdf" });
});

describe("LessonExportPanel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    calls.requestUpload.mockResolvedValue({ asset: { uuid: "a2" }, upload: { url: "/u", method: "PUT", headers: {} } });
    calls.complete.mockResolvedValue({ uuid: "a2", status: "ready" });
  });

  it("attaches the board's PDF to the board's lesson through the lesson's own upload (US5-1)", async () => {
    calls.record.mockResolvedValue({ export: "e1", attachment: { uuid: "a2" }, replaced: false, can_replace: true });
    render(<LessonExportPanel board={board([])} pageCount={1} renderPdf={renderPdf} />);

    fireEvent.click(await screen.findByRole("button", { name: WB.lessonExport.attach }));

    await waitFor(() => expect(screen.getByRole("status").textContent).toBe(WB.lessonExport.attached));
    expect(calls.requestUpload).toHaveBeenCalledWith("l1", expect.objectContaining({ kind: "document", role: "attachment" }));
    expect(calls.record).toHaveBeenCalledWith("b1", { lesson: "l1", asset: "a2" });
    expect(calls.replace).not.toHaveBeenCalled();
  });

  it("replaces an earlier attachment and says so (US5-3)", async () => {
    calls.replace.mockResolvedValue({ export: "e1", attachment: { uuid: "a2" }, replaced: true, can_replace: true });
    render(
      <LessonExportPanel
        board={board([{ uuid: "e1", lesson: { uuid: "l1", title: "الحركة" }, attachment: { uuid: "a1" }, can_replace: true }])}
        pageCount={1}
        renderPdf={renderPdf}
      />,
    );

    fireEvent.click(await screen.findByRole("button", { name: WB.lessonExport.replace }));

    await waitFor(() => expect(screen.getByRole("status").textContent).toBe(WB.lessonExport.replaced));
    expect(calls.replace).toHaveBeenCalledWith("b1", "e1", { asset: "a2" });
  });

  it("tells whoever may not replace to ask the course's teacher BEFORE drawing anything (US5-4)", async () => {
    render(
      <LessonExportPanel
        board={board([{ uuid: "e1", lesson: { uuid: "l1", title: "الحركة" }, attachment: { uuid: "a1" }, can_replace: false }])}
        pageCount={1}
        renderPdf={renderPdf}
      />,
    );

    expect(await screen.findByText(WB.lessonExport.askTeacher)).toBeTruthy();
    const button = screen.getByRole("button", { name: WB.lessonExport.replace }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    fireEvent.click(button);
    expect(renderPdf).not.toHaveBeenCalled();
  });

  it("takes another tab's attachment from a 409, so the next press replaces it", async () => {
    calls.record.mockRejectedValue(
      new ApiError("conflict", 409, { code: "already_exported", export: "e9", attachment: { uuid: "a9" }, can_replace: true }),
    );
    render(<LessonExportPanel board={board([])} pageCount={1} renderPdf={renderPdf} />);

    fireEvent.click(await screen.findByRole("button", { name: WB.lessonExport.attach }));

    await waitFor(() => expect(screen.getByRole("alert").textContent).toBe(WB.lessonExport.takenElsewhere));
    expect(screen.getByRole("button", { name: WB.lessonExport.replace })).toBeTruthy();
  });
});
