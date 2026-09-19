import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import OrdersPage from "./page";
import type { Order } from "@/lib/types";
import { formatMinorMoney } from "@/lib/labels";

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

  /*
    ⚠️ WAIT FOR THE LOADING INDICATOR TO GO, NOT FOR THE EMPTY STATE TO BE ABSENT.
    The empty state is absent WHILE THE PAGE IS STILL LOADING too, so that
    condition is true on the first tick and `waitFor` returns before any row
    exists — every assertion below then runs against the skeleton. It passed on a
    fast machine, where React had flushed anyway, and failed on CI's slower runner:
    «Unable to find an element with the text: شراء أرصدة» over a body full of
    `animate-pulse`.
  */
  await waitFor(() => {
    expect(screen.queryByRole("status", { name: "جارٍ التحميل" })).toBeNull();
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

/** The summary banner, by its accessible name — never by the figure it prints. */
function owedRegion(): HTMLElement {
  return screen.getByRole("status", { name: "المطلوب سداده" });
}

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
          sessionCount: null,
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

  it("names the COUNT when the plan was sold by sessions, not «null يوماً»", async () => {
    /*
     * ⛔ 036. The buyer of hours has no duration at all, and `null % 30 === 0` is
     * true in JavaScript — so before `planShape` this cell read «null يوماً» to
     * the person who had already paid, with nothing failing anywhere.
     *
     * ⚠️ AND THE COUNT IS THREE, NOT TWELVE. Twelve and thirty are exactly the
     * band where a template literal happens to agree with Arabic agreement, so a
     * case written with one passes over «٣ حصّة».
     */
    await show([
      order({
        kind: "subscription",
        subscription: {
          mode: "cohort",
          planUuid: "p-2",
          planTitle: "باقة الحصص",
          durationDays: null,
          sessionCount: 3,
          sessionType: "group",
          cohortUuid: "c-1",
          cohortName: "مجموعة السبت",
          teacherUuid: "t-1",
          teacherName: "Demo Teacher",
        },
      }),
    ]);

    expect(screen.getByText("٣ حصص · حصص جماعية · مجموعة السبت")).toBeDefined();
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

describe("the amount still owed", () => {
  /*
  | ⚠️ «كم عليّ أدفع» لم يكنْ على هذه الشاشةِ أصلاً، فكانَ يُجابُ بجمعِ عمودِ
  | المبالغِ بالعين. والمجموعُ هنا يُقاسُ لا يُوصَف: توكيدٌ على وجودِ السطرِ وحدَه
  | يمرُّ فوقَ مجموعٍ خاطئ.
  */
  it("counts what is open and leaves out what was already approved", async () => {
    await show([
      order({ uuid: "o-1", status: "pending", amount_minor: 45_000 }),
      order({ uuid: "o-2", status: "rejected", amount_minor: 5_000 }),
      // ⚠️ معتمَدٌ خارجَ المجموع: لا شيءَ مطلوبٌ عليه.
      order({ uuid: "o-3", status: "approved", amount_minor: 90_000 }),
    ]);

    /*
    | ⚠️ داخلَ المنطقةِ المسمّاة، لا في الصفحةِ كلِّها: عمودُ المبالغِ يطبعُ
    | السلسلةَ نفسَها، فبحثٌ عامٌّ يجدُ صفّاً ويظنُّه المجموع.
    |
    | ⚠️ و`textContent` لا `getByText`: `Intl` يفصلُ الرقمَ عن العملةِ بمسافةٍ
    | غيرِ فاصلة، ومُطبِّعُ testing-library يحوّلُها إلى مسافةٍ عاديّةً في الصفحةِ
    | ولا يلمسُ السلسلةَ المتوقَّعة — فلا يتطابقان أبداً.
    */
    expect(owedRegion().textContent).toContain(formatMinorMoney(50_000, "QAR"));
  });

  it("never adds up somebody else's order, on the officer's own screen", async () => {
    /*
    | ⚠️ الموظّفُ يرى طلباتِ غيرِه هنا، ومجموعٌ من كلِّ صفٍّ ظاهرٍ يقولُ له إنّه
    | يدينُ للمنصّةِ بكلِّ ما لم يُسدَّدْ عليها.
    */
    await show([
      order({ uuid: "o-1", status: "pending", amount_minor: 45_000 }),
      order({ uuid: "o-2", status: "pending", amount_minor: 900_000, is_mine: false }),
    ]);

    expect(owedRegion().textContent).toContain(formatMinorMoney(45_000, "QAR"));
  });

  it("shows no total rather than a wrong one when two currencies are open", async () => {
    // مجموعٌ عبرَ عملتَينِ رقمٌ ليسَ مالاً بأيِّ عملة، تحتَ عنوانٍ يقولُ إنّه
    // المطلوبُ سداده. الغيابُ فجوة، والرقمُ الخاطئُ كذب.
    await show([
      order({ uuid: "o-1", status: "pending", amount_minor: 45_000, currency: "QAR" }),
      order({ uuid: "o-2", status: "pending", amount_minor: 45_000, currency: "USD" }),
    ]);

    expect(screen.queryByRole("status", { name: "المطلوب سداده" })).toBeNull();
  });
});

describe("the status strip", () => {
  it("narrows the table to one status", async () => {
    await show([
      order({ uuid: "o-1", status: "pending", course_title: "أساسيّات التفاضل" }),
      order({ uuid: "o-2", status: "approved", course_title: "الكيمياء العضوية" }),
    ]);

    expect(screen.getByText("الكيمياء العضوية")).toBeDefined();

    fireEvent.click(screen.getByRole("button", { name: /بانتظار الدفع/ }));

    expect(screen.getByText("أساسيّات التفاضل")).toBeDefined();
    expect(screen.queryByText("الكيمياء العضوية")).toBeNull();
  });

  it("gives every status on the page a tile, «ملغى» included", async () => {
    /*
    | ⛔ الشريطُ كانَ قائمةً مكتوبةً باليدِ فيها أربعٌ من خمس: `OrderStatus` يحملُ
    | `cancelled` أيضاً، فكانَ «الكل: ٢» فوقَ بلاطاتٍ مجموعُها واحد، والصفُّ
    | الملغى ظاهرٌ في الجدولِ ولا بلاطةَ تصلُ إليه. وهو الآنَ مشتقٌّ من الصفوف،
    | فحالةٌ سادسةٌ تُضافُ غداً تَظهرُ بنفسِها.
    */
    await show([
      order({ uuid: "o-1", status: "pending" }),
      order({ uuid: "o-2", status: "cancelled" }),
    ]);

    expect(screen.getByRole("button", { name: /ملغى/ })).toBeDefined();
  });

  it("draws no tile for a status nobody on this page has", async () => {
    // بلاطةٌ تقولُ «٠ مرفوض» على شاشةِ من له طلبٌ واحدٌ قيدَ الدفعِ هي زرٌّ لا
    // يفعلُ شيئاً، ونصفُ الشريطِ منها.
    await show([order({ uuid: "o-1", status: "pending" })]);

    expect(screen.queryByRole("button", { name: /مرفوض/ })).toBeNull();
  });

  it("is not drawn at all over an empty history", async () => {
    // خمسةُ أزرارٍ تؤدّي كلُّها إلى «لا طلبات» هي خياراتٌ بلا أثر، على شاشةِ من
    // لم يشترِ شيئاً بعد.
    await show([]);

    expect(screen.queryByRole("group", { name: "تصفية الطلبات بالحالة" })).toBeNull();
    expect(screen.getByText("لا طلبات في سجلّك")).toBeDefined();
  });
});
