import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SignedInRedirect } from "./SignedInRedirect";

const replace = vi.fn();
let params = new URLSearchParams();
let auth: { user: unknown; loading: boolean } = { user: null, loading: false };

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
  useSearchParams: () => params,
}));

vi.mock("sonner", () => ({ toast: { info: vi.fn() } }));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => auth,
  homePathFor: () => "/dashboard",
}));

function draw() {
  return render(
    <SignedInRedirect>
      <p>النموذج</p>
    </SignedInRedirect>,
  );
}

beforeEach(() => {
  replace.mockClear();
  params = new URLSearchParams();
  auth = { user: null, loading: false };
});

describe("SignedInRedirect", () => {
  it("shows the form to a guest and moves nobody", () => {
    draw();

    expect(screen.getByText("النموذج")).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();
  });

  it("sends somebody who arrives signed in home, without drawing the form", () => {
    auth = { user: { uuid: "u-1" }, loading: false };

    draw();

    expect(screen.queryByText("النموذج")).toBeNull();
    expect(replace).toHaveBeenCalledWith("/dashboard");
  });

  it("honours a safe next, and an invitation before it", () => {
    auth = { user: { uuid: "u-1" }, loading: false };
    params = new URLSearchParams("next=/teachers/abc");

    draw();
    expect(replace).toHaveBeenLastCalledWith("/teachers/abc");

    replace.mockClear();
    params = new URLSearchParams("invitation=tok-1&next=/teachers/abc");

    draw();
    expect(replace).toHaveBeenLastCalledWith("/invitations/tok-1");
  });

  it("refuses an off-site next", () => {
    auth = { user: { uuid: "u-1" }, loading: false };
    params = new URLSearchParams("next=https://evil.example.com/");

    draw();

    expect(replace).toHaveBeenCalledWith("/dashboard");
  });

  it("never moves a session created on the page itself", () => {
    const { rerender } = draw();

    // A guest signs in here: `user` appears after the first settled answer.
    auth = { user: { uuid: "u-1" }, loading: false };
    rerender(
      <SignedInRedirect>
        <p>النموذج</p>
      </SignedInRedirect>,
    );

    expect(replace).not.toHaveBeenCalled();
  });
});
