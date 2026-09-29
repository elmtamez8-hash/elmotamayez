import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import ShipmentQueuePage from "./page";

/*
| ⚠️ THE MOCK ANSWERS WITH THE SHAPE THE SERVER SENDS, NOT THE SHAPE THE CLIENT
| HOPED FOR. `ShipmentController::update()` returns a bare Resource and
| `JsonResource::withoutWrapping()` is global, so the PATCH body IS the shipment.
| The page read `res.data.uuid`, threw inside its own `try`, and printed
| «حدث خطأ» over a move the server had just made — and told the buyer about.
| A test that mocked `{ data: … }` would have been green over exactly that.
*/

const get = vi.fn();
const patch = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    patch: (path: string, body: unknown) => patch(path, body),
  },
}));

const pending = {
  uuid: "sh-1",
  status: "pending",
  status_label: "بانتظار التجهيز",
  next_statuses: [{ value: "prepared", label: "جُهّز" }],
  tracking_ref: null,
  status_changed_at: null,
  recipient_name: "مريم",
};

describe("the shipment queue", () => {
  it("moves the row and shows no error when the server answers with the bare shipment", async () => {
    get.mockResolvedValue({ data: [pending] });
    patch.mockResolvedValue({
      ...pending,
      status: "prepared",
      status_label: "جُهّز",
      next_statuses: [],
    });

    render(<ShipmentQueuePage />);

    fireEvent.click(await screen.findByRole("button", { name: "جُهّز" }));

    await waitFor(() => expect(screen.getByText("انتهت")).toBeTruthy());
    expect(patch).toHaveBeenCalledWith("/store/shipments/sh-1", { status: "prepared" });
    expect(screen.queryByRole("alert")).toBeNull();
  });
});

describe("narrowing the queue", () => {
  const delivered = {
    ...pending,
    uuid: "sh-2",
    status: "delivered",
    status_label: "وصل",
    next_statuses: [],
    recipient_name: "خالد",
  };

  it("filters by state with the chips, and by the recipient with the search", async () => {
    get.mockResolvedValue({ data: [pending, delivered] });

    render(<ShipmentQueuePage />);

    fireEvent.click(await screen.findByRole("button", { name: "وصل ١" }));
    expect(screen.queryByText("مريم")).toBeNull();
    expect(screen.getByText("خالد")).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "الكل ٢" }));
    fireEvent.change(screen.getByLabelText("ابحث في الشحنات"), { target: { value: "مريم" } });
    expect(screen.getByText("مريم")).toBeTruthy();
    expect(screen.queryByText("خالد")).toBeNull();
  });

  /*
  | ⚠️ THE ENDPOINT PAGES AT TWENTY. Per-state figures counted over a first page
  | would print a number the server never said; past one page only its total shows.
  */
  it("shows only the server's total when the queue runs past one page", async () => {
    get.mockResolvedValue({ data: [pending, delivered], meta: { total: 45, current_page: 1, last_page: 3 } });

    render(<ShipmentQueuePage />);

    const strip = await screen.findByRole("group", { name: "ملخّص الشحنات" });

    expect(strip.textContent).toContain("كلّ الشحنات");
    expect(strip.textContent).not.toContain("وصل");
  });
});
