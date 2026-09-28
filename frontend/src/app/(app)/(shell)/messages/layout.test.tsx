import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Conversation } from "@/lib/conversations";

import MessagesLayout from "./layout";

/*
 * The sidebar re-reads `/conversations` on every message that arrives and every
 * one the reader sends. It used to show the grey skeleton for each of those, so
 * the list blinked on every line of a live conversation (two-account test,
 * 2026-09-28). The skeleton is for the FIRST load only; a refresh keeps the rows
 * on screen and swaps the new ones in when they come.
 */

type Deferred<T> = { promise: Promise<T>; resolve: (value: T) => void; reject: (error: unknown) => void };

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });

  return { promise, resolve, reject };
}

const calls = vi.hoisted(() => ({ list: [] as Array<Deferred<{ data: Conversation[] }>> }));

vi.mock("@/lib/conversations", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/conversations")>();

  return {
    ...actual,
    conversations: {
      ...actual.conversations,
      list: () => {
        const next = deferred<{ data: Conversation[] }>();
        calls.list.push(next);

        return next.promise;
      },
      online: () => Promise.resolve({ online: [] }),
    },
  };
});

vi.mock("@/lib/echo", () => ({ listen: () => Promise.resolve(() => undefined) }));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: { uuid: "u-1" } }) }));
vi.mock("next/navigation", () => ({ usePathname: () => "/messages" }));

function row(uuid: string, name: string, body: string): Conversation {
  return {
    uuid,
    kind: "private",
    student_name: name,
    student_uuid: null,
    counterparty_name: name,
    counterparty_avatar_url: null,
    can_moderate: false,
    last_message: { body, created_at: "2026-09-28T10:00:00+00:00" },
    updated_at: "2026-09-28T10:00:00+00:00",
  } as unknown as Conversation;
}

const skeleton = () => screen.queryByRole("status", { name: "جارٍ التحميل" });

async function answer(index: number, rows: Conversation[]): Promise<void> {
  await act(async () => {
    calls.list[index].resolve({ data: rows });
  });
}

describe("MessagesLayout — the conversation list", () => {
  beforeEach(() => {
    calls.list = [];
  });

  it("shows the skeleton on the first load only, and keeps the rows through a refresh", async () => {
    render(
      <MessagesLayout>
        <div />
      </MessagesLayout>,
    );

    expect(skeleton()).not.toBeNull();

    await answer(0, [row("c-1", "سلمى", "أهلاً")]);

    expect(skeleton()).toBeNull();
    expect(screen.getByText("أهلاً")).toBeTruthy();

    // A message arrives: the list is asked again…
    act(() => {
      window.dispatchEvent(new CustomEvent("conversations:changed"));
    });

    expect(calls.list).toHaveLength(2);
    // …and while it is asking, the rows stay and no skeleton appears.
    expect(skeleton()).toBeNull();
    expect(screen.getByText("أهلاً")).toBeTruthy();

    await answer(1, [row("c-1", "سلمى", "سؤال جديد")]);

    expect(skeleton()).toBeNull();
    expect(screen.getByText("سؤال جديد")).toBeTruthy();
    expect(screen.queryByText("أهلاً")).toBeNull();
  });

  it("lets only the newest of two overlapping refreshes land", async () => {
    render(
      <MessagesLayout>
        <div />
      </MessagesLayout>,
    );

    await answer(0, [row("c-1", "سلمى", "الأولى")]);

    act(() => {
      window.dispatchEvent(new CustomEvent("conversations:changed"));
      window.dispatchEvent(new CustomEvent("conversations:changed"));
    });

    await answer(2, [row("c-1", "سلمى", "الأحدث")]);
    await answer(1, [row("c-1", "سلمى", "القديمة")]);

    expect(screen.getByText("الأحدث")).toBeTruthy();
    expect(screen.queryByText("القديمة")).toBeNull();
  });

  it("keeps the rows when a refresh fails, and says the list may be behind", async () => {
    render(
      <MessagesLayout>
        <div />
      </MessagesLayout>,
    );

    await answer(0, [row("c-1", "سلمى", "أهلاً")]);

    act(() => {
      window.dispatchEvent(new CustomEvent("conversations:changed"));
    });

    await act(async () => {
      calls.list[1].reject(new Error("offline"));
    });

    expect(screen.getByText("أهلاً")).toBeTruthy();
    expect(screen.getByText(/تعذّر تحديث المحادثات/)).toBeTruthy();
    expect(skeleton()).toBeNull();
  });
});
