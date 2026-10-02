import { describe, expect, it } from "vitest";

import { migrateCustomData } from "./custom-data";

describe("migrateCustomData", () => {
  it("keeps an unknown kind exactly as it arrived — never dropped", () => {
    const fromTheFuture = { kind: "molecule", v: 3, smiles: "C1=CC=CC=C1" };

    expect(migrateCustomData(fromTheFuture)).toBe(fromTheFuture);
  });

  it("leaves a value that is not whiteboard data alone", () => {
    expect(migrateCustomData(null)).toBeNull();
    expect(migrateCustomData("text")).toBe("text");
    expect(migrateCustomData({ note: "excalidraw's own" })).toEqual({ note: "excalidraw's own" });
  });

  it("keeps a current-version quran object flat and unchanged", () => {
    const quran = { kind: "quran", v: 1, surah: 112, from: 1, to: 4, edition: "tanzil-uthmani-1.1" };

    expect(migrateCustomData(quran)).toEqual(quran);
  });

  it("returns a copy, so migrating never mutates the element it read", () => {
    const table = { kind: "table", v: 1, dir: "rtl", rows: [], colWidths: [] };
    const migrated = migrateCustomData(table);

    expect(migrated).toEqual(table);
    expect(migrated).not.toBe(table);
  });
});
