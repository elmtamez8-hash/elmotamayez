import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import { createAutosave, KEEPALIVE_LIMIT, SERVER_DEBOUNCE_MS, type AutosaveDeps, type ScenePut } from "@/lib/whiteboard/autosave";
import type { DraftStore, PageDraft } from "@/lib/whiteboard/draft-store";

/*
| Spec 039 · US2 — the autosave's rules, with fake timers and every dependency
| injected (T057). Each test names the rule; a test that would stay green with the
| rule removed is no test.
*/

type Pending = { body: ScenePut; resolve: (v: { version: number; client_rev: number }) => void; reject: (e: unknown) => void };

function harness(options: { drafts?: DraftStore } = {}) {
  const calls: Pending[] = [];
  const drafts = new Map<string, PageDraft>();
  const store: DraftStore = options.drafts ?? {
    get: async (k) => drafts.get(k) ?? null,
    put: async (k, d) => {
      drafts.set(k, d);
      return "ok";
    },
    remove: async (k) => {
      drafts.delete(k);
      return "ok";
    },
  };
  const deps: AutosaveDeps = {
    boardUuid: "b",
    userUuid: "u",
    tab: "tab-1",
    put: vi.fn((_page: string, body: ScenePut) => new Promise<{ version: number; client_rev: number }>((resolve, reject) => calls.push({ body, resolve, reject }))),
    putKeepalive: vi.fn(),
    drafts: store,
    idle: (fn) => {
      const t = setTimeout(fn, 0);
      return () => clearTimeout(t);
    },
    onState: vi.fn(),
    onConflict: vi.fn(),
    onLockLost: vi.fn(),
    onRefused: vi.fn(),
  };
  const save = createAutosave(deps);
  save.track("p1", 1, 0);
  save.setHolding(true);

  return { save, deps, calls, drafts };
}

const lastState = (deps: AutosaveDeps) => (deps.onState as ReturnType<typeof vi.fn>).mock.calls.at(-1)?.[0];
const conflict = (version: number, scene: string) =>
  new ApiError("x", 409, { code: "version_conflict", version, scene });

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe("autosave", () => {
  it("sends one request 1.5 s after drawing stops, and marks dirty only on a real change", async () => {
    const { save, calls } = harness();

    save.change("p1", 0, () => "same"); // the hash it already had
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    expect(calls).toHaveLength(0);

    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS - 1);
    expect(calls).toHaveLength(0);
    await vi.advanceTimersByTimeAsync(1);
    expect(calls).toHaveLength(1);
    expect(calls[0].body).toEqual({ tab: "tab-1", version: 1, client_rev: 1, scene: "a" });
  });

  it("queues three changes made during one unanswered PUT as ONE more request, carrying the last", async () => {
    const { save, calls, deps } = harness();
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    expect(lastState(deps)).toBe("saving");

    for (const [hash, scene] of [[2, "b"], [3, "c"], [4, "d"]] as const) {
      save.change("p1", hash, () => scene);
      await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    }
    expect(calls).toHaveLength(1);

    calls[0].resolve({ version: 2, client_rev: 1 });
    await vi.advanceTimersByTimeAsync(0);
    expect(calls).toHaveLength(2);
    expect(calls[1].body).toMatchObject({ version: 2, client_rev: 2, scene: "d" });

    calls[1].resolve({ version: 3, client_rev: 2 });
    await vi.advanceTimersByTimeAsync(0);
    expect(calls).toHaveLength(2);
    expect(lastState(deps)).toBe("saved");
  });

  it("retries a lost answer with the SAME rev and scene, and sends newer work only after it", async () => {
    const { save, calls, deps } = harness();
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    calls[0].reject(new TypeError("Failed to fetch"));
    await vi.advanceTimersByTimeAsync(0);
    expect(lastState(deps)).toBe("offline");

    save.change("p1", 2, () => "b");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    expect(calls).toHaveLength(2);
    expect(calls[1].body).toMatchObject({ client_rev: 1, scene: "a" });

    calls[1].resolve({ version: 2, client_rev: 1 });
    await vi.advanceTimersByTimeAsync(0);
    expect(calls[2].body).toMatchObject({ version: 2, client_rev: 2, scene: "b" });
  });

  it("stops the page on a version conflict — later changes do not resume saving", async () => {
    const { save, calls, deps } = harness();
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    calls[0].reject(conflict(5, "server"));
    await vi.advanceTimersByTimeAsync(0);
    expect(deps.onConflict).toHaveBeenCalledWith("p1", { version: 5, scene: "server" });

    save.change("p1", 2, () => "b");
    await vi.advanceTimersByTimeAsync(60_000);
    expect(calls).toHaveLength(1);
  });

  it("on lock_lost writes the draft and stops everything", async () => {
    const { save, calls, deps, drafts } = harness();
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    save.change("p1", 2, () => "b");
    calls[0].reject(new ApiError("x", 409, { code: "lock_lost" }));
    await vi.advanceTimersByTimeAsync(0);

    expect(deps.onLockLost).toHaveBeenCalled();
    expect(drafts.get("board:b:page:p1:user:u")).toMatchObject({ scene: "b", dirty: true });
    await vi.advanceTimersByTimeAsync(60_000);
    expect(calls).toHaveLength(1);
  });

  it("does not retry a permanent refusal", async () => {
    const { save, calls, deps } = harness();
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    calls[0].reject(new ApiError("x", 422, { code: "bad_link" }));
    await vi.advanceTimersByTimeAsync(60_000);

    expect(calls).toHaveLength(1);
    expect(deps.onRefused).toHaveBeenCalledWith("bad_link");
    expect(lastState(deps)).toBe("failed");
  });

  it("finishes the PUT in flight before a handover releases the lock", async () => {
    const { save, calls } = harness();
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);

    let drained = false;
    void save.drain().then(() => (drained = true));
    await vi.advanceTimersByTimeAsync(0);
    expect(drained).toBe(false);

    calls[0].resolve({ version: 2, client_rev: 1 });
    await vi.advanceTimersByTimeAsync(0);
    expect(drained).toBe(true);
  });

  it("cancels a deleted page's timer and its draft", async () => {
    const { save, calls, drafts } = harness();
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(0); // the idle draft
    expect(drafts.size).toBe(1);

    save.remove("p1");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    expect(calls).toHaveLength(0);
    expect(drafts.size).toBe(0);
  });

  it("flushes on pagehide only with no PUT in flight and under 64 KB", async () => {
    const { save, deps } = harness();
    save.change("p1", 1, () => "a");
    expect(save.flush().unsaved).toBe(true);
    expect(deps.putKeepalive).toHaveBeenCalledTimes(1);

    const big = harness();
    big.save.change("p1", 1, () => "ع".repeat(KEEPALIVE_LIMIT / 2)); // 64 KB of BYTES, but only 32 K characters
    expect(big.save.flush().unsaved).toBe(true);
    expect(big.deps.putKeepalive).not.toHaveBeenCalled();

    const busy = harness();
    busy.save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    busy.save.change("p1", 2, () => "b");
    expect(busy.save.flush().unsaved).toBe(true);
    expect(busy.deps.putKeepalive).not.toHaveBeenCalled();

    const clean = harness();
    expect(clean.save.flush().unsaved).toBe(false);
  });

  it("writes no draft and sends nothing from a read-only tab", async () => {
    const { save, calls, drafts } = harness();
    save.setHolding(false);
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);

    expect(calls).toHaveLength(0);
    expect(drafts.size).toBe(0);
  });

  it("says the protection is off when the device refuses to store drafts", async () => {
    const refusing: DraftStore = { get: async () => "unavailable", put: async () => "unavailable", remove: async () => "unavailable" };
    const { save, calls, deps } = harness({ drafts: refusing });
    save.change("p1", 1, () => "a");
    await vi.advanceTimersByTimeAsync(SERVER_DEBOUNCE_MS);
    calls[0].resolve({ version: 2, client_rev: 1 });
    await vi.advanceTimersByTimeAsync(0);

    expect(lastState(deps)).toBe("unprotected");
  });
});
