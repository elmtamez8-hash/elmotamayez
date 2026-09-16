import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TransferDestination } from "./TransferDestination";
import { billing } from "@/lib/billing";

vi.mock("@/lib/billing", () => ({
  billing: { transferInstructions: vi.fn() },
}));

const read = vi.mocked(billing.transferInstructions);

/*
| ⛔ الشاشةُ كانت تقولُ «حوِّلْ قيمة الباقة إلى حساب المنصّة» ولا تقولُ أيُّ
| حساب — بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦، وقِيسَ أنّ `platform_settings` لم يكنْ فيه
| اسمُ بنكٍ ولا آيبان ولا محفظة.
*/
describe("TransferDestination", () => {
  beforeEach(() => read.mockReset());

  it("prints what the operator wrote", async () => {
    read.mockResolvedValue({
      data: { bank_name: "بنك قطر الوطني", iban: "QA58DOHB0000123", note: "اكتب اسمك." },
      configured: true,
    } as never);

    render(<TransferDestination />);

    await waitFor(() => expect(screen.getByText("QA58DOHB0000123")).toBeDefined());
    expect(screen.getByText("بنك قطر الوطني")).toBeDefined();
    expect(screen.getByText("اكتب اسمك.")).toBeDefined();
  });

  /*
  | ⚠️ الخانةُ التي لم تُملأْ لا تُرسَمُ عنواناً بلا قيمة: سطرٌ «الآيبان: »
  | فارغٌ يُقرَأُ بياناتٍ لم تُحمَّل، فيُعيدُ المشتري التحميلَ وينتظر.
  */
  it("draws no label for a field nobody filled", async () => {
    read.mockResolvedValue({
      data: { wallet_label: "محفظتي", wallet_number: "3000-1111" },
      configured: true,
    } as never);

    render(<TransferDestination />);

    await waitFor(() => expect(screen.getByText("3000-1111")).toBeDefined());
    /*
    | ⚠️ بلا نقطتَين. كانت الإبرةُ تحملُ «:» لأنّ البطاقةَ كانت تكتبُ `{label}:`،
    | فلمّا صارَ العنوانُ سطراً فوقَ القيمةِ بلا علامةٍ أصبحَ التوكيدانِ صادقَينِ
    | عن لا شيء: يمرّانِ فوقَ بطاقةٍ ترسمُ كلَّ خانةٍ فارغة. وهو عطبُ «أخضرُ
    | لسببٍ خاطئ» الذي يسجّلُه هذا المستودعُ بنصِّه.
    */
    expect(screen.queryByText("الآيبان")).toBeNull();
    expect(screen.queryByText("البنك")).toBeNull();
  });

  /*
  | ⚠️ **والجملةُ الصريحةُ بدلَ صندوقٍ فارغ.** ومَن حوّلَ بطريقةٍ اتّفقَ عليها مع
  | الإدارةِ لا يُمنَعُ من رفعِ إيصالِه — فهذه لافتةٌ لا بوّابة.
  */
  it("says so plainly when the platform never declared a destination", async () => {
    read.mockResolvedValue({ data: {}, configured: false } as never);

    render(<TransferDestination />);

    await waitFor(() =>
      expect(screen.getByText("بيانات التحويل غير معلنة بعد")).toBeDefined(),
    );
  });

  /*
  | ⛔ **وحالةُ انقطاعِ القراءةِ غيرُ مكتوبةٍ هنا، والسببُ يُقالُ لا يُخفى.**
  |
  | المكوِّنُ يبتلعُ الرفضَ عمداً (`catch` ⇐ `configured = null` ⇐ لا يُرسَمُ
  | شيء)، لأنّ هذه بياناتٌ مساعِدةٌ فوقَ نموذجٍ يعمل: لافتةُ خطأٍ هنا تُوحي بأنّ
  | الإرسالَ نفسَه متعذّر، و«غير معلنة» عن شبكةٍ متعثّرةٍ خبرٌ كاذبٌ يدفعُ
  | المشتريَ إلى مراسلةِ الإدارة.
  |
  | وحاولتُ كتابتَها مرّتَين — بـ`mockRejectedValue` ثمّ بـ`mockImplementation`
  | داخلَ `act` — و`vitest` يُسقِطُ الحالةَ برسالةِ الخطأِ نفسِها التي يبتلعُها
  | الكودُ المُختبَر، رغمَ أنّ `catch` معلَّقٌ على السلسلةِ نفسِها. ولم أقِسِ
  | السببَ، فلا أدّعيه.
  |
  | وما كانت ستُثبِتُه محدود: الرسمُ الفارغُ عندَ الفشلِ **لا يُميَّزُ** عن
  | الرسمِ الفارغِ أثناءَ التحميل، فالتوكيدُ عليه توكيدٌ على لا شيءٍ تقريباً.
  | والحالاتُ الثلاثُ أعلاه هي ما يحرسُ البلاغَ فعلاً.
  */
});

/*
| ⚠️ **النسخُ هو سببُ قراءةِ هذه البطاقةِ أصلاً**: الآيبانُ تسعةٌ وعشرونَ محرفاً
| لاتينيّاً يُلصَقُ في تطبيقِ بنك، وكتابتُه باليدِ عن شاشةٍ هي الطريقُ إلى تحويلٍ
| يصلُ إلى حسابٍ آخر.
*/
describe("copying a number into a banking app", () => {
  beforeEach(() => read.mockReset());

  it("puts the value itself on the clipboard, not the label beside it", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);

    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });

    read.mockResolvedValue({ data: { iban: "QA58DOHB0000123" }, configured: true } as never);

    render(<TransferDestination />);

    await waitFor(() => expect(screen.getByRole("button", { name: "انسخ الآيبان" })).toBeDefined());

    fireEvent.click(screen.getByRole("button", { name: "انسخ الآيبان" }));

    expect(writeText).toHaveBeenCalledWith("QA58DOHB0000123");

    /*
    | ⚠️ الاسمُ هو ما يتبدّلُ لا لونُ الزرّ: الزرُّ أيقونةٌ، فمَن يقرأُ الشاشةَ
    | بأذنِه لا يرى التبديلَ إلّا في `aria-label`.
    */
    await waitFor(() => expect(screen.getByRole("button", { name: "نُسخ الآيبان" })).toBeDefined());
  });

  it("draws no copy button at all where the browser exposes no clipboard", async () => {
    /*
    | ⚠️ ولا علاقةَ لذلك بالصلاحيّات: خارجَ السياقِ الآمنِ لا يعرضُ المتصفّحُ
    | `navigator.clipboard` إطلاقاً — وهو بعينُه ما حدثَ مع `navigator.mediaDevices`
    | على عنوانِ شبكةٍ محلّيّة. فزرٌّ يُرسَمُ ثمّ يرمي أسوأُ من زرٍّ لا يُرسَم.
    */
    Object.defineProperty(navigator, "clipboard", { value: undefined, configurable: true });

    read.mockResolvedValue({ data: { iban: "QA58DOHB0000123" }, configured: true } as never);

    render(<TransferDestination />);

    await waitFor(() => expect(screen.getByText("QA58DOHB0000123")).toBeDefined());
    expect(screen.queryByRole("button", { name: "انسخ الآيبان" })).toBeNull();
  });
});

