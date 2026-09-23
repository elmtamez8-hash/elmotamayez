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
