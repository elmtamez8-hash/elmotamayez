import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";

import { UnlockPanel } from "@/components/sessions/UnlockPanel";
import type { SessionContentOffer } from "@/lib/class-sessions";

/*
| ٠٣٥ · T052 — زرٌّ بلا رقمٍ ليسَ اختياراً.
|
| ⛔ والتوكيدُ على **النصِّ المعروض**، لا على وجودِ عنصر. قرارُ المالكِ أنّ
| الطالبَ يقرّرُ بحرّيّةٍ يشترطُ أن يعرفَ الثمنَ قبلَ الضغط، فالثلاثةُ — كم
| سيُخصَم · كم يملكُ · ما الذي سيُفتَح — إمّا أن تكونَ مقروءةً على الشاشةِ أو لا
| تكونَ موجودة. لوحةٌ تعرضُ زرّاً وحدَه تمرُّ على أيِّ توكيدٍ يعدُّ الأزرار.
|
| ⚠️ ولا شيءَ من هذا يراهُ الخادم: هذه أسئلةٌ عن ما يُرسَمُ في متصفّح، وهي بالضبط
| ما تُجيبُه vitest ولا تُجيبُه pest.
*/

vi.mock("@/lib/class-sessions", async () => {
  const actual = await vi.importActual<Record<string, unknown>>("@/lib/class-sessions");

  return { ...actual, classSessions: { unlock: vi.fn() } };
});

function offer(overrides: Partial<SessionContentOffer> = {}): SessionContentOffer {
  return {
    credits: 1,
    owned_credits: 3,
    available_credits: 3,
    opens: { lesson: 2, assignment: 1 },
    available_until: null,
    purchase_url: "/billing/purchase",
    ...overrides,
  };
}

describe("UnlockPanel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("states the price, the balance and what opens — all three, in words", () => {
    render(<UnlockPanel sessionUuid="s-1" offer={offer()} />);

    // كم سيُخصَم
    expect(screen.getByText(/حصة واحدة/)).toBeTruthy();
    // ما الذي سيُفتَح — أعدادٌ وأصناف
    expect(screen.getByText(/درسان/)).toBeTruthy();
    expect(screen.getByText(/واجب/)).toBeTruthy();
    // كم يملك
    expect(screen.getByText(/رصيدُكَ المتاحُ الآن/)).toBeTruthy();
  });

  it("names no titles of what it will open", () => {
    /*
     | ⚠️ قائمةُ عناوينِ أوراقِ ساعةٍ هي خطّةُ درسٍ لمن لم يحضرْها — نفسُ الخطِّ
     | الذي ترسمُه قائمةُ الحقولِ العامّةِ على معرِّفِ الدرس. العرضُ يحملُ أعداداً
     | وأصنافاً فقط، وهذا ما يقيسُه هذا التوكيد: لا سبيلَ لتسريبِ عنوانٍ لأنّ لا
     | عنوانَ في الحمولةِ أصلاً.
    */
    const withTitles = offer() as SessionContentOffer & { opens: Record<string, number> };

    render(<UnlockPanel sessionUuid="s-1" offer={withTitles} />);

    expect(Object.keys(withTitles.opens).every((key) => typeof withTitles.opens[key] === "number")).toBe(true);
    expect(screen.queryByText(/ورقة عمل الفصل/)).toBeNull();
  });

  it("offers the road out even when the balance is enough", () => {
    /*
     | ⛔ حاضرٌ دائماً، لا حينَ يعجِزُ الرصيدُ وحدَه. شاشةٌ يتغيّرُ شكلُها بما
     | وجدَته تجعلُ وجودَ الزرِّ نفسِه إعلاناً عن حالةِ الرصيد، وتجعلُ من نسيَ
     | الحالةَ الثانيةَ يشحنُ رفضاً بلا مخرج (FR-013).
    */
    render(<UnlockPanel sessionUuid="s-1" offer={offer()} />);

    expect(screen.getByRole("link", { name: "اشترِ رصيداً" }).getAttribute("href")).toBe(
      "/billing/purchase",
    );
  });

  it("keeps the road out — and adds a sentence — when the balance is short", () => {
    render(<UnlockPanel sessionUuid="s-1" offer={offer({ available_credits: 0, owned_credits: 0 })} />);

    expect(screen.getByRole("link", { name: "اشترِ رصيداً" })).toBeTruthy();
    // ⚠️ الجملةُ هي المخرج. زرٌّ رماديٌّ بلا سببٍ هو «غير مسموح» بوجهٍ ألطف.
    expect(screen.getByText(/لا يكفي لفتحِ هذه الحصّة/)).toBeTruthy();
  });

  it("says how much is frozen elsewhere when owned and available disagree", () => {
    /*
     | ⚠️ الفرقُ بينَ المملوكِ والمتاحِ هو كلُّ قاعدةِ الحجزِ في ٠٣٥. طالبٌ يرى
     | «٣ حصص» في صفحةِ رصيدِه ويُرفَضُ هنا بلا تفسيرٍ يقرأُ ذلك عطباً.
    */
    render(<UnlockPanel sessionUuid="s-1" offer={offer({ owned_credits: 3, available_credits: 1 })} />);

    expect(screen.getByText(/محجوزتان/)).toBeTruthy();
  });

  it("warns before the press when the material has an end date", () => {
    // FR-039ج — الفتحُ لا يمدُّ مدّةَ الاحتفاظ، فالثمنُ أن يُقالَ ذلك قبلَ الضغط.
    render(<UnlockPanel sessionUuid="s-1" offer={offer({ available_until: "2026-12-01T10:00:00+00:00" })} />);

    expect(screen.getByText(/لا يمدِّدُ هذه المدّة/)).toBeTruthy();
  });
});
