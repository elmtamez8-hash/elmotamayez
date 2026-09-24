import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { SiteFooter } from "./SiteFooter";

/*
| The footer offers no subscription it cannot keep.
|
| A newsletter `<form>` with no `onSubmit` and no route behind it reloaded the
| page and let the visitor believe they had signed up.
*/

vi.mock("@/lib/platform", () => ({
  platformIdentity: async () => ({ name: "المتميّز", supportWhatsapp: null }),
}));

vi.mock("@/components/ui/BrandMark", () => ({ BrandMark: () => null }));

describe("SiteFooter", () => {
  it("carries no newsletter form", async () => {
    const { container } = render(await SiteFooter());

    expect(container.querySelector("form")).toBeNull();
    expect(screen.queryByRole("textbox", { name: /النشرة/ })).toBeNull();
    // The rest of the footer still renders.
    expect(screen.getByRole("link", { name: "الكورسات" })).toBeDefined();
  });
});
