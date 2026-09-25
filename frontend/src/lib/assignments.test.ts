import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| The teacher's download of a handed-in file.
|
| The file route sits behind `auth:sanctum` as well as a relative signature, so
| the only working shape is: refetch the list (the signed url lives five
| minutes), then FETCH the path with the bearer through `api.download()` — with
| the `/api/v1` prefix taken off once, because `download()` adds it back.
*/

const get = vi.fn();
const download = vi.fn();

vi.mock("./api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./api")>()),
  api: {
    get: (...args: unknown[]) => get(...args),
    download: (...args: unknown[]) => download(...args),
  },
}));

const { assignments } = await import("./assignments");

beforeEach(() => {
  vi.clearAllMocks();
  download.mockResolvedValue(undefined);
});

describe("assignments.openFile", () => {
  it("refetches the list and downloads the fresh signed path with the bearer helper", async () => {
    get.mockResolvedValue({
      data: [
        { uuid: "other", file_url: "/api/v1/submissions/other/file?signature=old" },
        {
          uuid: "sub-1",
          file_url: "/api/v1/submissions/sub-1/file?expires=1&reader=r&signature=fresh",
          file_name: "worksheet.pdf",
        },
      ],
    });

    await assignments.openFile("as-1", "sub-1");

    expect(get).toHaveBeenCalledWith("/manage/assignments/as-1/submissions");
    expect(download).toHaveBeenCalledWith(
      "/submissions/sub-1/file?expires=1&reader=r&signature=fresh",
      "worksheet.pdf",
    );
  });

  it("refuses, rather than downloading nothing, when the row carries no file", async () => {
    get.mockResolvedValue({ data: [{ uuid: "sub-1" }] });

    await expect(assignments.openFile("as-1", "sub-1")).rejects.toMatchObject({ status: 404 });
    expect(download).not.toHaveBeenCalled();
  });
});
