import { render } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import manifest from "../manifest";
import { StartRedirect } from "./StartRedirect";

/*
| The installed app opens `/start`, which decides (owner decision 2026-09-27):
| a session goes to the dashboard, a visitor to the public home.
*/

const replace = vi.fn();
const hasAuthToken = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
}));

vi.mock("@/lib/api", async () => {
  const actual = await vi.importActual<typeof import("@/lib/api")>("@/lib/api");
  return { ...actual, hasAuthToken: () => hasAuthToken() };
});

vi.mock("@/lib/platform", () => ({ platformName: async () => "المنصّة" }));

beforeEach(() => {
  replace.mockReset();
  hasAuthToken.mockReset();
});

describe("StartRedirect", () => {
  it("opens the dashboard for a signed-in reader", () => {
    hasAuthToken.mockReturnValue(true);

    render(<StartRedirect />);

    expect(replace).toHaveBeenCalledWith("/dashboard");
  });

  it("opens the public home for a visitor", () => {
    hasAuthToken.mockReturnValue(false);

    render(<StartRedirect />);

    expect(replace).toHaveBeenCalledWith("/");
  });

  it("is where the installed app starts", async () => {
    // The route above is only reachable because the manifest names it.
    expect((await manifest()).start_url).toBe("/start");
  });
});
