import { act, render } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuthProvider, useAuth } from "./auth-context";

/*
| Security scan 2026-10-10, F5: signing out left the device subscribed, so the
| next person on a shared device kept reading this account's notifications.
*/

const calls: string[] = [];
const unsubscribe = vi.fn();

vi.mock("@/lib/api", () => ({
  auth: { logout: () => (calls.push("logout"), Promise.resolve()), me: vi.fn() },
  setToken: vi.fn(),
  setSessionUuid: vi.fn(),
  clearToken: vi.fn(),
}));

vi.mock("@/lib/push", () => ({
  unsubscribeFromPush: () => (calls.push("unsubscribe"), Promise.reject(new Error("offline"))),
  currentSubscription: () => Promise.resolve({ unsubscribe }),
}));

let logout: () => Promise<void>;

function Grab() {
  logout = useAuth().logout;
  return null;
}

beforeEach(() => {
  calls.length = 0;
  unsubscribe.mockClear();
});

describe("logout", () => {
  it("drops the push subscription first, and in the browser even when the server cannot be told", async () => {
    await act(async () => {
      render(
        <AuthProvider>
          <Grab />
        </AuthProvider>,
      );
    });

    await act(() => logout());

    expect(calls).toEqual(["unsubscribe", "logout"]);
    expect(unsubscribe).toHaveBeenCalledOnce();
  });
});
