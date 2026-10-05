import { describe, expect, it, vi } from "vitest";

import { createPictureCache } from "@/lib/whiteboard/picture-cache";

describe("createPictureCache", () => {
  it("a thumbnail's peek hands nothing to the canvas; a new canvas holds nothing", async () => {
    const cache = createPictureCache(async () => new Blob(["x"], { type: "image/jpeg" }));

    expect((await cache.peek(["a", "b"])).map((p) => p.id)).toEqual(["a", "b"]);
    expect(cache.held()).toBe(0);

    expect(await cache.take(["a", "b"])).toHaveLength(2);
    expect(cache.held()).toBe(2);
    expect(await cache.take(["a"])).toHaveLength(0); // handed over once

    cache.forget();
    expect(cache.held()).toBe(0);
    expect(await cache.take(["a"])).toHaveLength(1); // the new canvas gets it again
  });

  it("a take dropped by a page that moved on, or begun for the canvas before, hands nothing over for good", async () => {
    const cache = createPictureCache(async () => new Blob(["x"], { type: "image/png" }));

    const taken = await cache.take(["a"]);
    cache.release(taken.map((p) => p.id));
    expect(await cache.take(["a"])).toHaveLength(1);

    const stale = cache.take(["b"]);
    cache.forget();
    expect(await stale).toHaveLength(0);
    expect(await cache.take(["b"])).toHaveLength(1);
  });

  it("a prefetch settles once every picture arrived or failed, and fetches each once", async () => {
    const fetch = vi.fn(async (id: string) => {
      if (id === "bad") throw new Error("gone");
      return new Blob(["x"], { type: "image/png" });
    });
    const cache = createPictureCache(fetch);
    await expect(cache.prefetch(["a", "bad"])).resolves.toBeUndefined();
    await cache.prefetch(["a", "b"]);
    expect(fetch.mock.calls.map(([id]) => id)).toEqual(["a", "bad", "b"]);
  });
});
