import { act, fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { ConfirmButton } from "./ConfirmButton";

/*
| زرٌّ يسألُ مرّةً، في مكانِه.
|
| «اكتم الجميع» و«أخرِج الجميع» يقعان في صفٍّ واحدٍ بينهما ثمانيةُ بكسل على هاتفِ
| مدرّس، وواحدٌ منهما يُستردُّ بلمسةٍ والآخرُ يُفرِغُ الحصّة. و«إنهاء الحصة» يُغلقُ
| البثَّ على الجميعِ بلمسةٍ واحدة، ولا رجعةَ فيه.
|
| ولا نافذةَ تأكيد: لا يوجدُ مكوّنُ حوارٍ في `components/ui/`، واختراعُ واحدٍ لسؤالٍ
| من كلمةٍ يعني حبسَ التركيزِ وقفلَ التمريرِ ومعالجَ Escape — و`window.confirm`
| غيرُ مُعرَّبٍ على بعضِ أجهزةِ أندرويد ويُجمِّدُ الصفحةَ والحصّةُ جارية.
*/

afterEach(() => {
  vi.useRealTimers();
});

describe("ConfirmButton", () => {
  it("does nothing on the first press, and says what the second will do", async () => {
    const onConfirm = vi.fn();

    render(
      <ConfirmButton confirmLabel="أكّد الإخراج" onConfirm={onConfirm}>
        إخراج
      </ConfirmButton>,
    );

    await userEvent.click(screen.getByRole("button", { name: "إخراج" }));

    // The whole point: one tap ejects nobody.
    expect(onConfirm).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "أكّد الإخراج" })).toBeTruthy();
  });

  it("fires on the second press", async () => {
    const onConfirm = vi.fn();

    render(
      <ConfirmButton confirmLabel="أكّد الإخراج" onConfirm={onConfirm}>
        إخراج
      </ConfirmButton>,
    );

    await userEvent.click(screen.getByRole("button", { name: "إخراج" }));
    await userEvent.click(screen.getByRole("button", { name: "أكّد الإخراج" }));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    // And it goes back to asking, so a second removal is a second decision.
    expect(screen.getByRole("button", { name: "إخراج" })).toBeTruthy();
  });

  it("disarms itself, so a half-pressed control does not lie in wait", () => {
    // ⚠️ `fireEvent`, NOT `userEvent`. The latter awaits real timers between its
    // simulated steps, so under `useFakeTimers` it waits for a clock nothing is
    // advancing and the test times out rather than failing.
    vi.useFakeTimers();
    const onConfirm = vi.fn();

    render(
      <ConfirmButton confirmLabel="أكّد الإخراج" onConfirm={onConfirm}>
        إخراج
      </ConfirmButton>,
    );

    fireEvent.click(screen.getByRole("button", { name: "إخراج" }));
    expect(screen.getByRole("button", { name: "أكّد الإخراج" })).toBeTruthy();

    // A teacher who looked away comes back to the safe state — otherwise the
    // next stray tap on a button they thought was idle removes somebody.
    act(() => {
      vi.advanceTimersByTime(5000);
    });

    expect(screen.getByRole("button", { name: "إخراج" })).toBeTruthy();
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
