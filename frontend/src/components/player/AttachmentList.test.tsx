import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

const requestAssetPlayback = vi.hoisted(() => vi.fn());
vi.mock("@/lib/media", () => ({ media: { requestAssetPlayback } }));

import { AttachmentList, type StudentAttachment } from "./AttachmentList";

const PDF: StudentAttachment = { uuid: "a1", original_filename: "السبورة.pdf", kind: "document", kind_label: "مستند", is_downloadable: false, is_ready: true };

describe("AttachmentList", () => {
  beforeEach(() => {
    requestAssetPlayback.mockReset();
    vi.spyOn(window, "open").mockReturnValue(null);
  });
  afterEach(() => vi.restoreAllMocks());

  it("opens a file through a grant asked for at the click", async () => {
    requestAssetPlayback.mockResolvedValue({ manifest_url: "/m/a1" });
    render(<AttachmentList lessonUuid="l1" attachments={[PDF]} />);

    fireEvent.click(screen.getByRole("button", { name: "فتح" }));

    await waitFor(() => expect(window.open).toHaveBeenCalledWith("/m/a1", "_blank", "noopener"));
    expect(requestAssetPlayback).toHaveBeenCalledWith("l1", "a1");
  });

  it("reads the list again when the file was replaced meanwhile, and says so", async () => {
    requestAssetPlayback.mockRejectedValue(new ApiError("not found", 404, {}));
    const onStale = vi.fn().mockResolvedValue(undefined);
    render(<AttachmentList lessonUuid="l1" attachments={[PDF]} onStale={onStale} />);

    fireEvent.click(screen.getByRole("button", { name: "فتح" }));

    expect(await screen.findByText("تغيّر هذا الملف، فحدّثنا القائمة. افتحه من جديد.")).toBeTruthy();
    expect(onStale).toHaveBeenCalledTimes(1);
  });

  it("keeps the message when the reload emptied the list, and shows a failed reload as it is", async () => {
    requestAssetPlayback.mockRejectedValue(new ApiError("not found", 404, {}));
    const { rerender } = render(<AttachmentList lessonUuid="l1" attachments={[PDF]} onStale={async () => undefined} />);
    fireEvent.click(screen.getByRole("button", { name: "فتح" }));
    await screen.findByText("تغيّر هذا الملف، فحدّثنا القائمة. افتحه من جديد.");
    rerender(<AttachmentList lessonUuid="l1" attachments={[]} onStale={async () => undefined} />);
    expect(screen.getByText("تغيّر هذا الملف، فحدّثنا القائمة. افتحه من جديد.")).toBeTruthy();

    rerender(<AttachmentList lessonUuid="l1" attachments={[PDF]} onStale={() => Promise.reject(new ApiError("gone", 404, {}))} />);
    fireEvent.click(screen.getByRole("button", { name: "فتح" }));
    await waitFor(() => expect(screen.queryByText("تغيّر هذا الملف، فحدّثنا القائمة. افتحه من جديد.")).toBeNull());
  });

  it("does not reload on a refusal (the student lost the lesson) or any other failure", async () => {
    requestAssetPlayback.mockRejectedValueOnce(new ApiError("forbidden", 403, {})).mockRejectedValue(new ApiError("server", 500, {}));
    const onStale = vi.fn().mockResolvedValue(undefined);
    render(<AttachmentList lessonUuid="l1" attachments={[PDF]} onStale={onStale} />);

    fireEvent.click(screen.getByRole("button", { name: "فتح" }));

    await screen.findByRole("alert");
    fireEvent.click(screen.getByRole("button", { name: "فتح" }));
    await screen.findByRole("alert");
    expect(onStale).not.toHaveBeenCalled();
  });
});
