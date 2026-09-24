import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { NotificationLink } from "./NotificationLink";

const openAdminPanel = vi.fn();

vi.mock("@/lib/admin-panel", () => ({
  openAdminPanel: (...args: unknown[]) => openAdminPanel(...args),
}));

describe("NotificationLink", () => {
  beforeEach(() => {
    openAdminPanel.mockReset();
    openAdminPanel.mockResolvedValue(undefined);
  });

  /*
  | ⚠️ A PANEL DESTINATION IS A TICKET, NOT A CLIENT NAVIGATION. A `<Link>` to
  | `/admin/...` is a client-side 404 (Next rewrites no `/admin`), and a plain
  | page request carries no Sanctum token, so the officer would meet a second
  | sign-in. The click must be taken over and handed to the handoff with the path.
  */
  it("sends a panel destination through the handoff with its path", () => {
    const onNavigate = vi.fn();

    render(
      <NotificationLink href="/admin/orders/o-1/edit" className="" onNavigate={onNavigate}>
        إيصال بانتظار المراجعة
      </NotificationLink>,
    );

    const link = screen.getByRole("link", { name: "إيصال بانتظار المراجعة" });
    const event = new MouseEvent("click", { bubbles: true, cancelable: true });

    fireEvent(link, event);

    expect(event.defaultPrevented).toBe(true);
    expect(openAdminPanel).toHaveBeenCalledWith("/admin/orders/o-1/edit");
    expect(onNavigate).toHaveBeenCalled();
  });

  it("leaves an app destination to the router", () => {
    render(
      <NotificationLink href="/dashboard?student=s-1" className="">
        نتيجة اختبار
      </NotificationLink>,
    );

    fireEvent.click(screen.getByRole("link", { name: "نتيجة اختبار" }));

    expect(openAdminPanel).not.toHaveBeenCalled();
  });
});
