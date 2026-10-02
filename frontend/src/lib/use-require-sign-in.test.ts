import { renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useRequireSignIn } from "./use-require-sign-in";

const push = vi.fn();
const auth = { user: null as unknown, loading: false };

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
  usePathname: () => "/whiteboard/abc-123",
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => auth,
}));

beforeEach(() => {
  push.mockReset();
  auth.user = null;
  auth.loading = false;
});

describe("useRequireSignIn", () => {
  it("sends a guest to sign-in with the address they were on", () => {
    window.history.replaceState(null, "", "/whiteboard/abc-123?page=2");

    renderHook(() => useRequireSignIn());

    expect(push).toHaveBeenCalledWith(`/login?next=${encodeURIComponent("/whiteboard/abc-123?page=2")}`);
  });

  it("waits while the session is still loading", () => {
    auth.loading = true;

    renderHook(() => useRequireSignIn());

    expect(push).not.toHaveBeenCalled();
  });

  it("leaves a signed-in teacher where they are", () => {
    auth.user = { uuid: "t-1" };

    const { result } = renderHook(() => useRequireSignIn());

    expect(push).not.toHaveBeenCalled();
    expect(result.current.user).toEqual({ uuid: "t-1" });
  });
});
