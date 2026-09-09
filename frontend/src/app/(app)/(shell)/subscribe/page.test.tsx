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

/* الحسابُ القارئُ — يُبدَّلُ لكلِّ حالة؛ الافتراضُ طالبٌ يشتري لنفسِه. */
let mockUser: Record<string, unknown> = { platform_role: "student" };

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: mockUser }),
}));

const listRelations = vi.fn();

vi.mock("@/lib/notifications", () => ({
  family: { list: () => listRelations() },
}));

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
    listRelations.mockReset();
    listRelations.mockResolvedValue({ data: [] });
    mockUser = { platform_role: "student" };
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

    /*
      ⚠️ BOTH FACTS INSIDE THE WAIT. The scroll happens in an effect that runs
      AFTER the refusal is painted, so asserting it beside the wait rather than
      inside it is a race the test loses on a slow machine — and it did, on CI.
    */
    await waitFor(() => {
      expect(screen.getByText("لم يُرسل الطلب")).toBeDefined();
      expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
    });
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

/*
|------------------------------------------------------------------------------
| A GUARDIAN SUBSCRIBES FOR A CHILD — reported by the user on 2026-09-08.
|------------------------------------------------------------------------------
|
| «منطقياً ماينفعش» — the screen sent the session's own account as the student,
| so a guardian who pressed «اشترك» became the learner: the enrolment and the
| group membership were written in their name and the child held nothing. The
| server refuses that now; these cases are the half that lets the payer pay.
|
| ⚠️ THE FILTER IS THE POINT OF THE FIRST TWO. An option the server will refuse
| is worse than no option — it is a refusal the reader cannot act on — and the
| two conditions guard two different refusals: a child with no account has
| nothing to enrol, and a relation without «payments» is one the server denies.
*/
const CHILD = {
  uuid: "rel-1",
  status: "active",
  student_name: "كريم",
  student_uuid: "child-1",
  permissions: [{ key: "payments", label: "المدفوعات" }],
};

async function openAsGuardian(relations: unknown[]) {
  mockUser = { platform_role: "parent" };
  listRelations.mockResolvedValue({ data: relations });

  const { default: SubscribePage } = await import("./page");

  render(<SubscribePage />);
}

describe("the subscription screen for a guardian", () => {
  beforeEach(() => {
    vi.resetModules();
    create.mockReset();
    uploadReceipt.mockReset();
    listRelations.mockReset();
    mockUser = { platform_role: "student" };
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("sends the chosen child, and will not send without one", async () => {
    create.mockResolvedValue({ data: { uuid: "order-uuid" } });
    uploadReceipt.mockResolvedValue({});

    await openAsGuardian([CHILD]);

    const plan = await screen.findByRole("radio");

    fireEvent.click(plan);
    fireEvent.change(document.querySelector("#subscribe-receipt") as HTMLInputElement, {
      target: { files: [new File(["x"], "receipt.png", { type: "image/png" })] },
    });

    // ⚠️ الزرُّ معطَّلٌ قبلَ اختيارِ الطالبِ رغمَ اكتمالِ الباقةِ والإيصال: الخادمُ
    // يرفضُ الفارغَ، وزرٌّ نشطٌ يقودُ إلى ٤٢٢ عن حقلٍ لم يُطلَبْ ملؤُه بعد.
    const send = screen.getByRole("button", { name: "أرسِلِ الطلب" });

    expect((send as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText(/لمن هذا الاشتراك/), {
      target: { value: "child-1" },
    });
    fireEvent.click(send);

    await waitFor(() => {
      expect(create).toHaveBeenCalled();
    });

    expect(create.mock.calls[0][0].student_uuid).toBe("child-1");
  });

  it("offers no child the server would refuse, and says what to do instead", async () => {
    await openAsGuardian([
      // أُضيفَ بالاسمِ ولم يفتحْ حساباً — لا حسابَ يُسجَّلُ فيه.
      { ...CHILD, uuid: "rel-2", student_name: "بدر", student_uuid: undefined },
      // وصايةٌ بلا صلاحيّةِ دفع — الخادمُ يرفضُها بجملةٍ واحدة.
      {
        ...CHILD,
        uuid: "rel-3",
        student_name: "سارة",
        student_uuid: "child-3",
        permissions: [{ key: "attendance", label: "الحضور" }],
      },
    ]);

    expect(await screen.findByText("لا يوجد ابن يمكنك الاشتراك له")).toBeDefined();
    expect(screen.queryByLabelText(/لمن هذا الاشتراك/)).toBeNull();
    expect(screen.getByRole("link", { name: "المرتبطون" }).getAttribute("href")).toBe("/family");
  });

  it("asks a student buying for themselves for nobody", async () => {
    const { default: SubscribePage } = await import("./page");

    render(<SubscribePage />);

    await screen.findByRole("radio");

    expect(screen.queryByLabelText(/لمن هذا الاشتراك/)).toBeNull();
    // ولا يُسألُ الخادمُ عن أبناءٍ لحسابٍ ليسَ وليَّ أمر.
    expect(listRelations).not.toHaveBeenCalled();
  });
});
