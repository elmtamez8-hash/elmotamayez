import { beforeEach, describe, expect, it, vi } from "vitest";

// The package itself does not load under jsdom; the adapter's own logic is what is tested.
vi.mock("@excalidraw/excalidraw", () => ({
  CaptureUpdateAction: { IMMEDIATELY: "immediately", NEVER: "never" },
  convertToExcalidrawElements: (elements: unknown[]) => elements,
  exportToBlob: vi.fn(),
  exportToCanvas: vi.fn(),
  exportToSvg: vi.fn(),
  hashElementsVersion: vi.fn(),
  newElementWith: (element: object, updates: object) => ({ ...element, ...updates }),
  restoreElements: (elements: unknown[]) => elements,
  setCustomTextMetricsProvider: vi.fn(),
  useHandleLibrary: vi.fn(),
}));

import { withStarter } from "@/lib/whiteboard/excalidraw-api";

const item = (id: string) => ({ id });
const starter = (...ids: string[]) => () => Promise.resolve(ids.map(item));

describe("the starter library", () => {
  beforeEach(() => localStorage.clear());

  it("joins the teacher's library once, after their own items, and is kept", async () => {
    const library = await withStarter([item("mine")], starter("s1", "s2"));
    expect(library.map((i) => i.id)).toEqual(["mine", "s1", "s2"]);
    expect(JSON.parse(localStorage.getItem("whiteboard.library") ?? "[]")).toHaveLength(3);
  });

  it("never brings back an item the teacher removed, and adds only what a newer file has", async () => {
    await withStarter([], starter("s1", "s2"));
    // s1 removed by the teacher; the file later gains s3.
    const library = await withStarter([item("s2")], starter("s1", "s2", "s3"));
    expect(library.map((i) => i.id)).toEqual(["s2", "s3"]);
  });

  it("leaves the library as it is when the file cannot be fetched", async () => {
    const mine = [item("mine")];
    expect(await withStarter(mine, () => Promise.reject(new Error("offline")))).toBe(mine);
    expect(localStorage.getItem("whiteboard.library.starter")).toBeNull();
  });
});
