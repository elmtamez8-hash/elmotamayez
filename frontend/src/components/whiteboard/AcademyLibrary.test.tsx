import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import { WB } from "@/lib/whiteboard/strings";

const list = vi.fn();
const share = vi.fn();
const remove = vi.fn();
vi.mock("@/lib/whiteboard/api", () => ({ boardLibrary: { list: () => list(), share: (b: unknown) => share(b), remove: (u: string) => remove(u) } }));

const placeShape = vi.fn();
const selected = vi.fn();
const { Pass } = vi.hoisted(() => ({ Pass: ({ children }: { children?: ReactNode }) => children }));
vi.mock("@/lib/whiteboard/excalidraw-api", () => ({
  DefaultSidebar: Object.assign(Pass, { TabTriggers: Pass }),
  Sidebar: { TabTrigger: Pass, Tab: Pass },
  dragShape: vi.fn(),
  placeShape: (...args: unknown[]) => placeShape(...args),
  selectedShape: () => selected(),
  shapePreview: () => Promise.resolve("blob:x"),
}));

import { AcademyLibrary } from "./AcademyLibrary";

const mine = { uuid: "s1", name: "مثلث", elements: [{ id: "a", type: "line" }], shared_by: "أحمد", created_at: "", can_delete: true };
const theirs = { ...mine, uuid: "s2", name: "خلية", can_delete: false };
const api = {} as never;

describe("AcademyLibrary", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    list.mockResolvedValue({ data: [mine, theirs] });
  });

  it("shows the academy's shapes, places one when pressed, and offers removal only where the server allows it", async () => {
    render(<AcademyLibrary api={api} canEdit />);
    await screen.findByText("خلية");
    expect(screen.getAllByRole("button", { name: WB.academy.remove })).toHaveLength(1);

    fireEvent.click(screen.getByTitle("مثلث"));
    expect(placeShape).toHaveBeenCalledWith(api, mine.elements);
  });

  it("shares the selection without its uploaded pictures, and names a full library", async () => {
    selected.mockReturnValue([
      { id: "r", type: "rectangle" },
      { id: "p", type: "image", fileId: "0190-uploaded" },
      { id: "t", type: "image", fileId: "template:grid:v1" },
    ]);
    share.mockResolvedValueOnce(mine).mockRejectedValueOnce(new ApiError("full", 409, { code: "library_full" }));
    render(<AcademyLibrary api={api} canEdit />);

    fireEvent.change(screen.getByLabelText(WB.academy.name), { target: { value: "شكل" } });
    fireEvent.click(screen.getByRole("button", { name: WB.academy.share }));
    await waitFor(() => expect(share).toHaveBeenCalledWith({ name: "شكل", elements: [{ id: "r", type: "rectangle" }, { id: "t", type: "image", fileId: "template:grid:v1" }] }));

    fireEvent.change(screen.getByLabelText(WB.academy.name), { target: { value: "آخر" } });
    fireEvent.click(screen.getByRole("button", { name: WB.academy.share }));
    expect(await screen.findByText(WB.academy.full)).toBeTruthy();
  });

  it("lets a reader without the pen look but not place or share", async () => {
    render(<AcademyLibrary api={api} canEdit={false} />);
    await screen.findByText("خلية");
    expect(screen.queryByRole("button", { name: WB.academy.share })).toBeNull();
    expect((screen.getByTitle("مثلث") as HTMLButtonElement).disabled).toBe(true);
  });
});
