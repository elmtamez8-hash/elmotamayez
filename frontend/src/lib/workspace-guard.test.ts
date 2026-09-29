import { act, render, screen } from "@testing-library/react";
import { createElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { WorkspaceSwitchedNotice } from "@/components/app/WorkspaceSwitchedNotice";
import { ApiError, api } from "./api";
import type { User } from "./types";
import {
  WORKSPACE_BROADCAST_KEY,
  WORKSPACE_HEADER,
  WORKSPACE_NOTICE_KEY,
  WORKSPACE_RELOAD_GUARD_MS,
  WORKSPACE_RELOAD_KEY,
  announceWorkspaceSwitch,
  onWorkspaceSwitchElsewhere,
  reloadForWorkspaceChange,
  setExpectedWorkspace,
} from "./workspace-guard";

/*
| The page names the workspace it draws in `X-Workspace`; the server answers a
| mismatch with 409 `workspace_changed`, and the tab reloads ONCE — never in a
| loop — then says where the account now is.
*/

const auth = vi.hoisted(() => ({ user: null as unknown, loading: false }));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => auth,
}));

let reload: ReturnType<typeof vi.fn>;
let fetchMock: ReturnType<typeof vi.fn>;

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function sentHeaders(): Record<string, string> {
  const init = fetchMock.mock.calls[0][1] as RequestInit;

  return init.headers as Record<string, string>;
}

beforeEach(() => {
  window.sessionStorage.clear();
  window.localStorage.clear();
  window.localStorage.setItem("auth_token", "t");
  setExpectedWorkspace(null);
  reload = vi.fn();
  vi.stubGlobal("location", { ...window.location, reload });
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("the X-Workspace header", () => {
  it("names the workspace the page believes it is in", async () => {
    setExpectedWorkspace("ws-a");
    fetchMock.mockResolvedValue(jsonResponse(200, { ok: true }));

    await api.get("/courses");

    expect(sentHeaders()[WORKSPACE_HEADER]).toBe("ws-a");
  });

  it("is absent before /auth/me has answered, and for an account in no workspace", async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { ok: true }));

    await api.get("/auth/me");

    expect(sentHeaders()).not.toHaveProperty(WORKSPACE_HEADER);
  });
});

describe("a 409 workspace_changed", () => {
  it("reloads the tab and leaves a note for the reloaded page", async () => {
    setExpectedWorkspace("ws-a");
    fetchMock.mockResolvedValue(jsonResponse(409, { code: "workspace_changed", message: "x" }));

    // The caller is kept waiting: an error flash would be replaced by the reload.
    const settled = vi.fn();
    void api.post("/courses", { title: "x" }).then(settled, settled);
    await vi.waitFor(() => expect(reload).toHaveBeenCalledTimes(1));

    expect(settled).not.toHaveBeenCalled();
    expect(window.sessionStorage.getItem(WORKSPACE_NOTICE_KEY)).toBe("1");
  });

  it("does not reload twice in a row — the second one is thrown, not looped", async () => {
    setExpectedWorkspace("ws-a");
    window.sessionStorage.setItem(WORKSPACE_RELOAD_KEY, String(Date.now()));
    fetchMock.mockResolvedValue(jsonResponse(409, { code: "workspace_changed", message: "x" }));

    await expect(api.get("/courses")).rejects.toBeInstanceOf(ApiError);
    expect(reload).not.toHaveBeenCalled();
  });

  it("reloads again once the guard window has passed", () => {
    const now = 1_000_000;
    window.sessionStorage.setItem(WORKSPACE_RELOAD_KEY, String(now - WORKSPACE_RELOAD_GUARD_MS - 1));

    expect(reloadForWorkspaceChange(now)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("leaves any other 409 to the caller", async () => {
    setExpectedWorkspace("ws-a");
    fetchMock.mockResolvedValue(jsonResponse(409, { message: "already taken" }));

    await expect(api.get("/courses")).rejects.toMatchObject({ status: 409 });
    expect(reload).not.toHaveBeenCalled();
  });
});

describe("the other tabs of the browser", () => {
  it("reload when one tab switches, if they show a workspace", () => {
    setExpectedWorkspace("ws-a");
    const stop = onWorkspaceSwitchElsewhere(() => reloadForWorkspaceChange());

    // The writer never hears its own storage event; a sibling tab does.
    window.dispatchEvent(new StorageEvent("storage", { key: WORKSPACE_BROADCAST_KEY, newValue: "1" }));
    stop();

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("stay put when they show no workspace", () => {
    const stop = onWorkspaceSwitchElsewhere(() => reloadForWorkspaceChange());

    window.dispatchEvent(new StorageEvent("storage", { key: WORKSPACE_BROADCAST_KEY, newValue: "1" }));
    stop();

    expect(reload).not.toHaveBeenCalled();
  });

  it("are told through localStorage", () => {
    announceWorkspaceSwitch();

    expect(window.localStorage.getItem(WORKSPACE_BROADCAST_KEY)).not.toBeNull();
  });
});

describe("WorkspaceSwitchedNotice", () => {
  const user = (name: string | null) =>
    ({ uuid: "u", current_workspace: name === null ? null : { uuid: "ws-b", name } }) as unknown as User;

  it("says where the account now is, once", async () => {
    window.sessionStorage.setItem(WORKSPACE_NOTICE_KEY, "1");
    auth.user = user("أكاديمية النور");

    await act(async () => {
      render(createElement(WorkspaceSwitchedNotice));
    });

    expect(screen.getByRole("status").textContent).toContain("تم تبديل مكان العمل إلى «أكاديمية النور».");
    expect(window.sessionStorage.getItem(WORKSPACE_NOTICE_KEY)).toBeNull();
  });

  it("shows nothing on an ordinary page load", async () => {
    auth.user = user("أكاديمية النور");

    await act(async () => {
      render(createElement(WorkspaceSwitchedNotice));
    });

    expect(screen.queryByRole("status")).toBeNull();
  });
});
