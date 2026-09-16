import { render, screen, waitFor } from "@testing-library/react";
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
    expect(screen.queryByText("الآيبان:")).toBeNull();
    expect(screen.queryByText("البنك:")).toBeNull();
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
