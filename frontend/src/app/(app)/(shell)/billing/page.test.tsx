import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BillingPage from "./page";

/*
| «رصيدي» — reached by a student AND by a guardian from the sidebar.
|
| ⚠️ It spoke to both as the student: «حصصك المتبقّية عند كل معلّم» over a
| guardian account that holds no credits, and a «أوافق على شروط…» card that
| would have recorded the GUARDIAN agreeing to owe for themselves. A guardian
| gets the two ways out that are theirs (the child's dashboard, the purchase
| screen that asks which child) and none of the student's sections.
*/

const get = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: vi.fn(),
  },
}));

let mockUser: Record<string, unknown> | null = null;

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: mockUser }) };
});

describe("billing page", () => {
  beforeEach(() => {
    get.mockReset();
    get.mockImplementation((path: string) => {
      if (path.startsWith("/billing/consents")) {
        return Promise.resolve({
          data: [
            {
              document: "deferred_payment_terms",
              label: "شروط الدفع المؤجّل",
              version: "1",
              consented_at: null,
            },
          ],
        });
      }

      return Promise.resolve({ data: [], meta: { last_page: 1 } });
    });
  });

  it("speaks to a student about their own credits and offers the terms", async () => {
    mockUser = { uuid: "s-1", platform_role: "student", permissions: [] };

    render(<BillingPage />);

    expect(screen.getByRole("heading", { name: "رصيدي" })).toBeTruthy();
    expect(
      await screen.findByRole("button", { name: /أوافق على شروط الدفع المؤجّل/ }),
    ).toBeTruthy();
  });

  it("gives a guardian no student sections and asks for nothing about themselves", async () => {
    mockUser = { uuid: "p-1", platform_role: "parent", permissions: [] };

    render(<BillingPage />);

    expect(screen.getByRole("heading", { name: "شراء حصص لأبنائي" })).toBeTruthy();
    expect(screen.getByRole("link", { name: "شراء حصص لابنك" }).getAttribute("href")).toBe(
      "/billing/purchase",
    );
    expect(screen.queryByText(/حصصك المتبقّية/)).toBeNull();

    await waitFor(() => expect(get).not.toHaveBeenCalled());
    expect(screen.queryByRole("button", { name: /أوافق/ })).toBeNull();
  });
});
