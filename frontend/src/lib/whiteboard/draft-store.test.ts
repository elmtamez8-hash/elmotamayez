import { describe, expect, it } from "vitest";

import { classifyDraft, draftKey, indexedDbDrafts, type PageDraft } from "@/lib/whiteboard/draft-store";

const draft = (over: Partial<PageDraft> = {}): PageDraft => ({ scene: "{}", ackedVersion: 3, rev: 1, dirty: true, ...over });

describe("classifyDraft", () => {
  it("discards nothing-to-restore", () => {
    expect(classifyDraft(null, 3)).toBe("discard");
    expect(classifyDraft(draft({ dirty: false }), 3)).toBe("discard");
  });

  it("offers a draft edited from the version the server still has", () => {
    expect(classifyDraft(draft(), 3)).toBe("restore");
  });

  it("asks when the server moved on — never decides silently", () => {
    expect(classifyDraft(draft(), 4)).toBe("ask");
  });
});

describe("indexedDbDrafts without IndexedDB", () => {
  // jsdom has no `indexedDB` — the same answer a private window or a full quota gives.
  it("answers unavailable instead of throwing", async () => {
    const key = draftKey("b", "p", "u");
    expect(key).toBe("board:b:page:p:user:u");
    await expect(indexedDbDrafts.get(key)).resolves.toBe("unavailable");
    await expect(indexedDbDrafts.put(key, draft())).resolves.toBe("unavailable");
    await expect(indexedDbDrafts.remove(key)).resolves.toBe("unavailable");
  });
});
