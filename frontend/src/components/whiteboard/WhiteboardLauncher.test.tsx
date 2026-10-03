import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { WB } from "@/lib/whiteboard/strings";

const calls = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), update: vi.fn() }));
vi.mock("@/lib/whiteboard/api", () => ({ boards: calls }));

import { WhiteboardLauncher } from "./WhiteboardLauncher";

describe("WhiteboardLauncher", () => {
  const win = { location: { href: "" }, close: vi.fn() };

  beforeEach(() => {
    vi.clearAllMocks();
    win.location.href = "";
    vi.spyOn(window, "open").mockReturnValue(win as unknown as Window);
  });
  afterEach(() => vi.restoreAllMocks());

  it("opens the class's board in its own window, with the hint to share that tab (US7-1)", async () => {
    calls.list.mockResolvedValue({ data: [{ uuid: "b1", title: "سبّورة" }] });
    render(<WhiteboardLauncher sessionUuid="s1" sessionTitle="حصة" />);

    fireEvent.click(await screen.findByRole("button", { name: WB.live.open }));

    await waitFor(() => expect(win.location.href).toBe("/whiteboard/b1"));
    expect(window.open).toHaveBeenCalledWith("", "whiteboard", "popup,width=1600,height=900");
    expect(calls.list).toHaveBeenCalledWith({ session: "s1" });
    expect(screen.getByText(WB.shareHint)).toBeTruthy();
  });

  it("with no board for the class, makes one for it — linked to the class (US7-3)", async () => {
    calls.list.mockImplementation(async (params: { session?: string }) => ({ data: params.session ? [] : [] }));
    calls.create.mockResolvedValue({ uuid: "b2" });
    render(<WhiteboardLauncher sessionUuid="s1" sessionTitle="حصة الجبر" />);

    fireEvent.click(await screen.findByRole("button", { name: WB.live.open }));
    fireEvent.click(await screen.findByRole("button", { name: WB.live.create }));

    await waitFor(() => expect(win.location.href).toBe("/whiteboard/b2"));
    expect(calls.create).toHaveBeenCalledWith({ title: "حصة الجبر", class_session: "s1" });

    // The class has its board now: a second press opens it and never makes another.
    fireEvent.click(await screen.findByRole("button", { name: WB.live.open }));
    await waitFor(() => expect(window.open).toHaveBeenCalledTimes(2));
    expect(calls.create).toHaveBeenCalledTimes(1);
  });

  it("shows nothing to someone the boards door refuses", async () => {
    calls.list.mockRejectedValue(new Error("403"));
    const { container } = render(<WhiteboardLauncher sessionUuid="s1" sessionTitle="حصة" />);

    await waitFor(() => expect(calls.list).toHaveBeenCalled());
    expect(container.textContent).toBe("");
  });
});
