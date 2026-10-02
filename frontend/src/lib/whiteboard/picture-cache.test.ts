import { describe, expect, it } from "vitest";

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
});
