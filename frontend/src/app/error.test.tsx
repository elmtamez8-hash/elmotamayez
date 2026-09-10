import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import ErrorBoundary from "./error";

/*
| حدُّ الخطأِ — البلاغُ الذي لم يكنْ فيه ما يُقتَبَس (٢٠٢٦-٠٩-١٠).
|
| ⚠️ بلا هذا الملفِّ تعرضُ Next جملتَها الإنجليزيّةَ الوحيدةَ «Application error…»
| بلا عنوانٍ ولا زرٍّ ولا رابط. وما يقيسُه هذا الملفُّ ليس الشكلَ بل **ما يمكنُ
| فعلُه**: أنَّ الرمزَ الذي يُقتَبَسُ في البلاغِ ظاهرٌ على الشاشة، وأنَّ الزرَّينِ
| فعلانِ مختلفانِ لا واحدٌ مكرَّر.
|
| ⚠️ و`console.error` مكتومٌ هنا عمداً لا مُهمَل: المكوّنُ يُسجّلُ في `useEffect`،
| فبلا كتمٍ يمتلئُ مخرجُ المجموعةِ بأخطاءٍ مصطنَعةٍ تُقرأُ رسوباً.
*/

const DIGEST = "3271866508";

beforeEach(() => {
  vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
  vi.restoreAllMocks();
});

function boom(digest?: string) {
  const reset = vi.fn();
  const error = Object.assign(new Error("انفجار"), digest ? { digest } : {});

  render(<ErrorBoundary error={error} reset={reset} />);

  return { reset };
}

describe("حدّ الخطأ", () => {
  it("يعرضُ رمزَ التشخيصِ ليُقتَبَسَ في البلاغ", () => {
    boom(DIGEST);

    /*
      ⚠️ هذا هو الحارسُ الحقيقيّ. البناءُ الإنتاجيُّ يُجرّدُ الرسالةَ والأثرَ ويُبقي
      هذا الهاشَ وحدَه — وهو نفسُه المكتوبُ في سجلِّ الخادم. فحذفُه من الشاشةِ
      يُعيدُ البلاغَ إلى ما كانَ عليه: «التطبيق وقع» بلا خيطٍ واحد.
    */
    expect(screen.getByText(DIGEST)).toBeTruthy();
  });

  it("لا يعِدُ برمزٍ حين لا يكونُ هناكَ رمز", () => {
    // A development throw carries no digest, and a label with nothing after it
    // is worse than no label: it reads as a field that failed to load.
    boom();

    expect(screen.queryByText(/أرفق هذا الرمز/)).toBeNull();
  });

  it("«إعادة المحاولة» تستدعي reset ولا تُعيدُ تحميلَ المستند", () => {
    const reload = vi.fn();
    vi.stubGlobal("location", { ...window.location, reload });

    const { reset } = boom(DIGEST);

    fireEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));

    expect(reset).toHaveBeenCalledTimes(1);
    expect(reload).not.toHaveBeenCalled();

    vi.unstubAllGlobals();
  });

  it("«إعادة تحميل الصفحة» تُعيدُ تحميلَ المستندِ ولا تستدعي reset", () => {
    /*
      ⚠️ الزرّانِ ليسا زرّاً واحداً. `reset()` يُعيدُ تصييرَ المقطعِ بالعميلِ الذي
      بيدِه، فلا يُصلحُ حزمةً قديمةً بقيَت في تبويبةٍ عبرَ نشرة — وذاك يحتاجُ
      تحميلَ مستندٍ جديد. اختبارٌ يضغطُ أحدَهما فقط يمرُّ على تنفيذٍ ربطَ
      الاثنَينِ بالفعلِ نفسِه.
    */
    const reload = vi.fn();
    vi.stubGlobal("location", { ...window.location, reload });

    const { reset } = boom(DIGEST);

    fireEvent.click(screen.getByRole("button", { name: "إعادة تحميل الصفحة" }));

    expect(reload).toHaveBeenCalledTimes(1);
    expect(reset).not.toHaveBeenCalled();

    vi.unstubAllGlobals();
  });

  it("يترك طريقاً للخروج بدل أن يكونَ طريقاً مسدوداً", () => {
    boom(DIGEST);

    expect(screen.getByRole("link", { name: "لوحة التحكم" })).toBeTruthy();
    expect(screen.getByRole("navigation", { name: "روابط بديلة" })).toBeTruthy();
  });
});
