import { describe, expect, it, vi } from "vitest";

import { PageStore } from "@/lib/whiteboard/page-store";

const page = (uuid: string, elements: unknown[]) => ({ uuid, position: 0, version: 3, scene: JSON.stringify({ v: 1, elements }), background_file: null });

describe("PageStore", () => {
  it("restores a page the first time it is read, and only that page", () => {
    const restore = vi.fn((elements: readonly unknown[]) => elements.map((e) => ({ ...(e as object), restored: true })));
    const store = PageStore.of([page("a", [{ id: "1" }]), page("b", [{ id: "2" }])], restore);

    expect(restore).not.toHaveBeenCalled();
    expect(store.has("b")).toBe(true);
    expect(store.isRestored("a")).toBe(false);

    expect(store.get("a")).toEqual([{ id: "1", restored: true }]);
    expect(store.get("a")).toBe(store.get("a")); // once
    expect(restore).toHaveBeenCalledTimes(1);
    expect(store.isRestored("a")).toBe(true);
    expect(store.isRestored("b")).toBe(false);
  });

  it("lets a write replace a page not read yet, and a delete remove it", () => {
    const restore = vi.fn((elements: readonly unknown[]) => elements);
    const store = PageStore.of([page("a", [{ id: "old" }]), page("b", [])], restore);

    store.set("a", [{ id: "new" }]);
    expect(store.get("a")).toEqual([{ id: "new" }]);
    expect(store.delete("b")).toBe(true);
    expect(store.has("b")).toBe(false);
    expect(restore).not.toHaveBeenCalled();
  });

  it("restores every page before anything walks it — a copy for the PDF has no blank pages", () => {
    const restore = vi.fn((elements: readonly unknown[]) => elements);
    const store = PageStore.of([page("a", [{ id: "1" }]), page("b", [{ id: "2" }])], restore);
    store.get("a");

    const copy = new Map(store);
    expect(copy.get("b")).toEqual([{ id: "2" }]);
    expect(store.size).toBe(2);
    expect(restore).toHaveBeenCalledTimes(2);
  });

  it("names every page's pictures without restoring any, templates aside", () => {
    const restore = vi.fn((elements: readonly unknown[]) => elements);
    const store = PageStore.of(
      [
        page("a", [{ type: "image", fileId: "p1" }, { type: "image", fileId: "template:grid:v1" }]),
        page("b", [{ type: "image", fileId: "p2", isDeleted: true }, { type: "image", fileId: "p3" }]),
      ],
      restore,
    );
    expect(store.pictureIds().sort()).toEqual(["p1", "p3"]);
    expect(restore).not.toHaveBeenCalled();
  });
});
