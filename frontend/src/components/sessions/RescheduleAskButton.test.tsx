import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

import { RescheduleAskButton } from "./RescheduleAskButton";

/*
| طلبُ تأجيلِ حصّةٍ واحدةٍ — من شاشةِ الطالب.
|
| ⚠️ `fireEvent`، لا `userEvent`: الأخيرُ ينتظرُ مؤقّتاتٍ حقيقيّةً بينَ خطواتِه،
| وقد كلّفَ هذه الشجرةَ مرّتَينِ من قبل.
|
| ⚠️ وjsdom لا يُنفِّذُ `showModal()` — مُرقَّعٌ في `vitest.setup.ts` — فما يُقاسُ
| هنا هو ما يُقرِّرُه المكوّنُ نفسُه: ما يُرسَل، ومتى، وماذا يُعرَضُ عندَ الرفض.
*/

const ask = vi.fn();

vi.mock("@/lib/reschedule-requests", () => ({
  rescheduleRequests: {
    ask: (...args: unknown[]) => ask(...args),
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
  ask.mockResolvedValue({});
});

function open() {
  render(<RescheduleAskButton sessionUuid="s-1" title="حصة الفيزياء" />);

  act(() => {
    fireEvent.click(screen.getByRole("button", { name: /اطلب تأجيلها/ }));
  });
}

describe("asking to move one lesson", () => {
  it("sends an absolute instant, never the naive wall clock the field holds", async () => {
    open();

    fireEvent.change(screen.getByLabelText(/الموعد المقترح/), {
      target: { value: "2026-10-11T18:00" },
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أرسِل الطلب" }));
    });

    const [uuid, startsAt] = ask.mock.calls.at(-1) as [string, string];

    expect(uuid).toBe("s-1");
    // The API runs on UTC; sending the field's own string books an hour that is
    // right only for a reader in the server's offset.
    expect(startsAt).not.toBe("2026-10-11T18:00");
    expect(new Date(startsAt).getTime()).toBe(new Date("2026-10-11T18:00").getTime());
  });

  it("prints the server's own sentence, because it names what the student can do", async () => {
    ask.mockRejectedValue(
      new ApiError("هناك طلب تأجيل قائم على هذه الحصة.", 422, {
        message: "هناك طلب تأجيل قائم على هذه الحصة.",
      }),
    );

    open();

    fireEvent.change(screen.getByLabelText(/الموعد المقترح/), {
      target: { value: "2026-10-11T18:00" },
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أرسِل الطلب" }));
    });

    // Not «تعذّر تنفيذ الإجراء»: a refusal the reader cannot act on is a refusal
    // they retry for ever.
    expect(await screen.findByText("هناك طلب تأجيل قائم على هذه الحصة.")).toBeTruthy();
  });

  it("says the ask is waiting rather than that the lesson moved", async () => {
    open();

    fireEvent.change(screen.getByLabelText(/الموعد المقترح/), {
      target: { value: "2026-10-11T18:00" },
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أرسِل الطلب" }));
    });

    // A screen reading «تم التأجيل» over a request nobody has answered is a
    // student who does not turn up on Saturday.
    expect(await screen.findByText(/بانتظار ردّ المدرّس/)).toBeTruthy();
  });
});
