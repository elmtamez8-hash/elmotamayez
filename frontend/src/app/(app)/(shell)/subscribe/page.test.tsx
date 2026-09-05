import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| 027 — THE REFUSAL IS AT THE TOP AND THE SEND BUTTON IS AT THE BOTTOM.
|
| Reported from a real purchase (2026-09-06): the server refused with 422, the
| danger Alert rendered exactly as designed — and the buyer, standing on the
| button, saw NOTHING happen and pressed it again. A message nobody can see is a
| swallowed error wearing markup.
|
| ⚠️ `scrollIntoView` DOES NOT EXIST IN JSDOM, so it is stubbed rather than
| asserted through a real scroll; what is measured is that the page ASKS for the
| sentence to be shown, which is the half that was missing.
*/

const create = vi.fn();
const uploadReceipt = vi.fn();

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams("course=course-uuid&cohort=cohort-uuid"),
}));

vi.mock("@/lib/subscribe", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/subscribe")>();

  return {
    ...actual,
    subscribe: {
      course: vi.fn(async () => ({
        data: {
          uuid: "course-uuid",
          title: "أساسيّات التفاضل",
          teacher: { name: "Demo Teacher" },
          cohorts: [{ uuid: "cohort-uuid", name: "مجموعة السبت", schedule: [] }],
        },
      })),
      plans: vi.fn(async () => ({
        data: [
          {
            uuid: "plan-uuid",
            title: "الشهري",
            duration_days: 30,
            session_type: "group",
            price_minor: 45_000,
            currency: "QAR",
          },
        ],
      })),
      create: (body: unknown) => create(body),
      uploadReceipt: (...args: unknown[]) => uploadReceipt(...args),
    },
  };
});

async function fillAndSend() {
  const { default: SubscribePage } = await import("./page");

  render(<SubscribePage />);

  const plan = await screen.findByRole("radio");

  fireEvent.click(plan);

  const receipt = document.querySelector<HTMLInputElement>("#subscribe-receipt");

  fireEvent.change(receipt as HTMLInputElement, {
    target: { files: [new File(["x"], "receipt.png", { type: "image/png" })] },
  });

  fireEvent.click(screen.getByRole("button", { name: "أرسِلِ الطلب" }));
}

describe("the subscription screen's refusal", () => {
  beforeEach(() => {
    vi.resetModules();
    create.mockReset();
    uploadReceipt.mockReset();
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("brings the reason into view instead of leaving it above the fold", async () => {
    create.mockRejectedValue(
      Object.assign(new Error("refused"), {
        status: 422,
        message: "هذه المجموعة لم تعد متاحة للانضمام.",
      }),
    );

    await fillAndSend();

    await waitFor(() => {
      expect(screen.getByText("لم يُرسل الطلب")).toBeDefined();
    });

    expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
  });

  it("scrolls nothing when the request went through", async () => {
    create.mockResolvedValue({ data: { uuid: "order-uuid" } });
    uploadReceipt.mockResolvedValue({});

    await fillAndSend();

    await waitFor(() => {
      expect(screen.getByText("وصل طلبك")).toBeDefined();
    });

    expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
  });
});
