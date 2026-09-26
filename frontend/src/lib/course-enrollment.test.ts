import { beforeEach, describe, expect, it, vi } from "vitest";

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
}));

const { grantsCourseAccess } = await import("./course-enrollment");

beforeEach(() => vi.clearAllMocks());

/*
| ⛔ The public course page used a 403 from the curriculum and the groups as its
| answer to «am I enrolled», on every visit (2026-09-26). The question is asked
| of the reader's own rows now — one request, shared by both providers.
*/
describe("grantsCourseAccess", () => {
  it("asks the reader's own enrolments for this one course, once for two askers", async () => {
    get.mockResolvedValue({ data: [{ uuid: "e-1", grants_access: true }] });

    const [first, second] = await Promise.all([grantsCourseAccess("c-1"), grantsCourseAccess("c-1")]);

    expect(first).toBe(true);
    expect(second).toBe(true);
    expect(get).toHaveBeenCalledTimes(1);
    expect(get).toHaveBeenCalledWith("/enrollments?course=c-1");
  });

  it("reads the door's answer, not the row's existence", async () => {
    // An expired or cancelled enrolment is listed and opens nothing.
    get.mockResolvedValue({ data: [{ uuid: "e-2", grants_access: false }] });

    expect(await grantsCourseAccess("c-2")).toBe(false);
  });

  it("asks again after it has answered — a purchase in between must count", async () => {
    get.mockResolvedValueOnce({ data: [] });
    expect(await grantsCourseAccess("c-3")).toBe(false);

    get.mockResolvedValueOnce({ data: [{ uuid: "e-3", grants_access: true }] });
    expect(await grantsCourseAccess("c-3")).toBe(true);
    expect(get).toHaveBeenCalledTimes(2);
  });

  it("answers «no» on a failure, never an error — nobody pressed anything", async () => {
    get.mockRejectedValue(new Error("offline"));

    expect(await grantsCourseAccess("c-4")).toBe(false);
  });
});
