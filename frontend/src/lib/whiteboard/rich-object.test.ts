import { describe, expect, it, vi } from "vitest";

// The package itself does not load under jsdom; the door's pure logic is what is tested.
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

import { magicStroke, replaceRichObject, type BoardApi } from "@/lib/whiteboard/excalidraw-api";
import { blankTable } from "@/lib/whiteboard/table";

describe("replaceRichObject", () => {
  it("keeps the element's id, place, angle, width and layer; only the picture and source change", () => {
    const table = { id: "t1", type: "image", fileId: "old", x: 40, y: 80, width: 600, height: 300, angle: 0.3, version: 4, customData: blankTable(2, 2) };
    const above = { id: "s1", type: "freedraw", version: 1 };
    let scene = [table, above] as unknown[];
    const api = {
      getSceneElementsIncludingDeleted: () => scene,
      updateScene: vi.fn(({ elements }: { elements: unknown[] }) => (scene = elements)),
    } as unknown as BoardApi;

    const data = blankTable(3, 2);
    expect(replaceRichObject(api, "t1", "new", 400, 300, data)).toBe(true);

    const [after, still] = scene as (typeof table)[];
    expect(after).toMatchObject({ id: "t1", fileId: "new", x: 40, y: 80, width: 600, angle: 0.3, version: 5, customData: data, crop: null });
    expect(after.height).toBe(450); // the new picture's proportions at the width the teacher chose
    expect(still).toBe(above); // the layer order is untouched

    // Gone meanwhile: nothing changes, and the caller is told.
    expect(replaceRichObject(api, "missing", "new2", 400, 300, data)).toBe(false);
  });
});

describe("magicStroke", () => {
  // As Excalidraw orders a page: the stroke drawn inside the frame sits BEFORE it.
  const sceneWith = (stroke: object) => {
    let scene = [stroke, { id: "page", type: "frame", isDeleted: false }] as unknown[];
    const api = {
      getAppState: () => ({ zoom: { value: 1 } }),
      getSceneElementsIncludingDeleted: () => scene,
      updateScene: vi.fn(({ elements }: { elements: unknown[] }) => (scene = elements)),
    } as unknown as BoardApi;
    return { api, scene: () => scene as Record<string, unknown>[] };
  };

  it("swaps a drawn loop for a clean ellipse in the same colour and frame, the stroke kept as deleted for undo", () => {
    const loop = Array.from({ length: 60 }, (_, i) => [100 + 80 * Math.cos((i / 59) * 2 * Math.PI), 60 + 50 * Math.sin((i / 59) * 2 * Math.PI)]);
    const { api, scene } = sceneWith({ id: "f1", type: "freedraw", x: 10, y: 20, points: loop, strokeColor: "#ffffff", strokeWidth: 4, opacity: 100, frameId: "page", isDeleted: false });

    expect(magicStroke(api, "f1")).toBe(true);
    const [stroke, frame, clean] = scene();
    expect(stroke).toMatchObject({ id: "f1", isDeleted: true });
    expect(frame).toMatchObject({ id: "page", isDeleted: false });
    expect(clean).toMatchObject({ type: "ellipse", strokeColor: "#ffffff", frameId: "page", roughness: 0 });
    expect(clean.x).toBeCloseTo(30, 0); // 10 + (100 − 80)
  });

  it("leaves a stroke it does not recognise, and anything that is not a stroke", () => {
    const zigzag = [[0, 0], [40, 60], [80, 0], [120, 60], [160, 0], [200, 60]];
    const { api } = sceneWith({ id: "f2", type: "freedraw", x: 0, y: 0, points: zigzag, strokeColor: "#000", strokeWidth: 4, opacity: 100, frameId: null, isDeleted: false });
    expect(magicStroke(api, "f2")).toBe(false);
    expect(magicStroke(api, "missing")).toBe(false);
    expect(api.updateScene).not.toHaveBeenCalled();
  });
});
