import { beforeEach, describe, expect, it, vi } from "vitest";

const calls: string[] = [];
const completeStatus = { value: "ready" };

vi.mock("@/lib/api", () => ({
  uploadToTicket: vi.fn(async () => {
    calls.push("upload");
  }),
}));
vi.mock("@/lib/whiteboard/api", () => ({
  boards: {
    requestFile: vi.fn(async (_b: string, body: { filename: string; size: number }) => {
      calls.push(`ticket:${body.filename}:${body.size}`);
      return { file: { uuid: "f-1" }, upload: { url: "/api/v1/media/upload/x", method: "PUT", headers: {} } };
    }),
    completeFile: vi.fn(async () => {
      calls.push("complete");
      return { uuid: "f-1", status: completeStatus.value };
    }),
  },
}));

import { fitWithin, ImageRefused, MAX_SIDE, uploadBoardImage } from "@/lib/whiteboard/image-insert";

beforeEach(() => {
  calls.length = 0;
  completeStatus.value = "ready";
});

describe("fitWithin", () => {
  it("keeps a picture that fits, and shrinks the long side to the limit keeping the ratio", () => {
    expect(fitWithin(1200, 800)).toEqual({ width: 1200, height: 800 });
    expect(fitWithin(5120, 2880)).toEqual({ width: MAX_SIDE, height: 1440 });
    expect(fitWithin(1000, 8000)).toEqual({ width: 320, height: MAX_SIDE });
  });
});

describe("uploadBoardImage", () => {
  const png = new Blob(["x".repeat(10)], { type: "image/png" });
  const prepare = vi.fn(async () => png);

  it("answers the file id only after ticket, bytes and a READY completion — in that order", async () => {
    const id = await uploadBoardImage("b", "tab", new File(["raw"], "صورة.webp", { type: "image/webp" }), prepare);

    expect(id).toBe("f-1");
    expect(calls).toEqual(["ticket:صورة.png:10", "upload", "complete"]);
  });

  it("refuses a file the server did not accept, so no page names it", async () => {
    completeStatus.value = "failed";

    await expect(uploadBoardImage("b", "tab", new File(["raw"], "a.png", { type: "image/png" }), prepare)).rejects.toBeInstanceOf(ImageRefused);
  });
});
