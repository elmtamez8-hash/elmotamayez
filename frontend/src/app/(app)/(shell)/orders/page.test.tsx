import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import OrdersPage from "./page";
import type { Order } from "@/lib/types";

/*
| «الطلبات» — ما اشتراه الصفُّ، ومتى يختفي «ادفع الآن». بُلِّغَ ٢٠٢٦-٠٩-٠٦.
|
| ⚠️ الصفُّ كانَ يقولُ «شراء أرصدة» ولا يقولُ كم حصّة، ويقولُ «اشتراك» ولا يقولُ
| شهريّاً أفرديّاً أم جماعيّاً — على الشاشةِ التي يتحقّقُ فيها الدافعُ ممّا دفعَ
| مقابلَه. والحقيقتانِ كانتا على الحمولةِ أصلاً.
|
| ⚠️ و«ادفع الآن» كانَ يظهرُ فوقَ إيصالٍ قائمٍ قيدَ المراجعة: المبلغُ نفسُه،
| مدعوّاً إليه مرّةً ثانية، عبرَ بوّابةٍ تأخذُه فعلاً.
*/

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path), upload: vi.fn() },
  errorMessage: () => "خطأ",
}));

function order(overrides: Partial<Order>): Order {
  return {
    uuid: "o-1",
    amount_minor: 45_000,
    currency: "QAR",
    provider: "manual",
    status: "pending",
    rejection_reason: null,
    approved_at: null,
    kind: "course",
    course_title: "أساسيّات التفاضل",
    has_receipt: false,
    is_mine: true,
    receipt_url: null,
    review_sla_hours: 48,
    subscription: null,
    credits: null,
    created_at: "2026-09-06T10:00:00+00:00",
    ...overrides,
  };
}

async function show(rows: Order[]) {
  get.mockResolvedValue({ data: rows });

  render(<OrdersPage />);

  await waitFor(() => {
    expect(screen.queryByText("لا طلبات في سجلّك")).toBeNull();
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("what the order row says was bought", () => {
  it("names the duration, the room size and the group of a subscription", async () => {
    await show([
      order({
        kind: "subscription",
        subscription: {
          mode: "cohort",
          planUuid: "p-1",
          planTitle: "الشهري",
          durationDays: 30,
          sessionType: "group",
          cohortUuid: "c-1",
          cohortName: "مجموعة السبت",
          teacherUuid: "t-1",
          teacherName: "Demo Teacher",
        },
      }),
    ]);

    expect(screen.getByText("الشهري")).toBeDefined();
    expect(
      screen.getByText("شهر واحد · حصص جماعية · مجموعة السبت"),
    ).toBeDefined();
  });

  it("counts the sessions a credit order bought instead of saying «شراء أرصدة» alone", async () => {
    await show([order({ kind: "credits", credits: 4 })]);

    expect(screen.getByText("٤ حصص")).toBeDefined();
  });

  it("falls back to the kind when the count was never loaded", async () => {
    // An absent `credits` key is a forgotten eager load, never a zero.
    await show([order({ kind: "credits", credits: null })]);

    expect(screen.getByText("شراء أرصدة")).toBeDefined();
  });
});

describe("the pay-now link", () => {
  it("goes away while a receipt is standing, and the receipt can be replaced", async () => {
    await show([
      order({ status: "under_review", has_receipt: true, receipt_url: "/r/1" }),
    ]);

    expect(screen.queryByText("ادفع الآن")).toBeNull();
    expect(screen.getByText("عرض الإيصال")).toBeDefined();
    // ⚠️ REPLACING WAS IMPOSSIBLE: the view branch won the moment a receipt
    // existed, so a wrong image could only be waited out.
    expect(screen.getByText("استبدل الإيصال")).toBeDefined();
  });

  it("stays on an order with no receipt yet", async () => {
    await show([order({ status: "pending" })]);

    expect(screen.getByText("ادفع الآن")).toBeDefined();
    expect(screen.getByText("ارفع الإيصال")).toBeDefined();
  });

  it("comes back on a refusal, because nothing there was accepted as paid", async () => {
    await show([
      order({
        status: "rejected",
        has_receipt: true,
        receipt_url: "/r/1",
        rejection_reason: "الصورة غير واضحة",
      }),
    ]);

    expect(screen.getByText("ادفع الآن")).toBeDefined();
    // The refused image is deliberately not offered back: what is needed is a
    // different one.
    expect(screen.queryByText("عرض الإيصال")).toBeNull();
    expect(screen.getByText("استبدل الإيصال")).toBeDefined();
  });
});
