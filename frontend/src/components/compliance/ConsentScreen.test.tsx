import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ConsentScreen } from "./ConsentScreen";
import { compliance } from "@/lib/compliance";

/*
 * ⚠️ GLOBALS ARE OFF IN THIS PROJECT'S VITEST CONFIG, so every helper is imported
 * by name, and the file sits under `src/` because the `include` glob is scoped
 * there — the default would swallow `e2e/*.spec.ts` and die inside Playwright's
 * runner.
 */
vi.mock("@/lib/compliance", () => ({
  compliance: { categories: vi.fn(), policy: vi.fn(), updateCategories: vi.fn() },
}));

const categories = vi.mocked(compliance.categories);
const policy = vi.mocked(compliance.policy);

const RECORDING = {
  key: "class_recording",
  label: "الظهور في تسجيلات الحصص (‏صوتاً وصورةً)",
  purpose: "تُسجَّل الحصص لتتمكّن أنت وزملاؤك من مراجعتها.",
  audience: "من حجز الحصة · المدرّس · مزوّد الفيديو",
  is_required: true,
  owning_module: "media",
  retain_days: 730,
  retention_label_ar: "٧٣٠ يوماً",
  expiry_behaviour: "delete" as const,
};

const REVIEW = {
  key: "review",
  label: "تقييماتك للمدرّسين",
  purpose: "لتساعد غيرك على الاختيار.",
  audience: "علنيّ",
  is_required: false,
  owning_module: "marketplace",
  retain_days: null,
  retention_label_ar: "يُحفظ ما دام الحساب قائماً",
  expiry_behaviour: null,
};

describe("ConsentScreen", () => {
  beforeEach(() => {
    categories.mockReset();
    policy.mockReset();

    categories.mockResolvedValue({ data: [RECORDING, REVIEW], processors: [] } as never);
    policy.mockResolvedValue({ version: "1.0", body_html: "<p>نصّ</p>" } as never);
  });

  /*
   * ⚠️ SC-003, AND IT IS AN ASSERTION ABOUT THE SENTENCE ON THE SCREEN.
   *
   * The catalogue row is asserted separately in the backend suite; what THAT
   * cannot see is whether a parent is actually shown it. The criterion is that
   * appearing in a class recording — voice and image — is named among the
   * REQUIRED categories, in words a person reads, before they consent. A test
   * that checked `is_required === true` in a fixture would pass against a screen
   * that never rendered the row at all.
   */
  it("names appearing in a class recording, in words, among the required categories", async () => {
    render(<ConsentScreen />);

    const heading = await screen.findByText("ما نجمعه ولا تعمل الخدمة بدونه");
    const requiredCard = heading.closest("div");

    expect(requiredCard?.textContent).toContain("صوتاً وصورةً");
    // And it is not merely present somewhere: it is NOT offered as a choice.
    expect(screen.queryByLabelText(/الظهور في تسجيلات الحصص/)).toBeNull();
  });

  /*
   * The other half of the same requirement.
   *
   * ⚠️ AN OPTIONAL CATEGORY MUST BE A REAL CONTROL. Rendering everything as prose
   * satisfies "the required one is named" and quietly removes the right to refuse
   * the optional ones, which is FR-007 with no way to exercise it.
   */
  it("offers every optional category as a control, unchecked", async () => {
    render(<ConsentScreen />);

    const box = (await screen.findByLabelText(/تقييماتك للمدرّسين/)) as HTMLInputElement;

    expect(box.type).toBe("checkbox");
    // ⚠️ UNCHECKED. A pre-ticked box is not a choice — the same rule the
    // registration form applies to its terms box.
    expect(box.checked).toBe(false);
  });

  /*
   * ⚠️ THE ACTION IS NOT OFFERED UNTIL THE POLICY TEXT HAS ARRIVED.
   *
   * A consent names the VERSION it was given for, and the server answers 409 to a
   * submission carrying a stale one — so a button that could be pressed before the
   * text loaded would record a signature against no particular words.
   *
   * ⚠️ AND THIS ASSERTS THE SKELETON, NOT A DISABLED BUTTON, because the first
   * version of this test asserted the wrong thing and failed: the screen awaits
   * BOTH calls together, so while either is outstanding there is no button in the
   * document at all. `disabled={version === ""}` on the control is therefore
   * belt-and-braces against a future refactor that loads them separately — it is
   * deliberately kept, and deliberately NOT claimed here as the thing under test.
   */
  it("offers no consent button until the policy text has loaded", async () => {
    policy.mockReturnValue(new Promise(() => {}) as never);

    render(<ConsentScreen />);

    // The categories resolved; the policy did not. Nothing actionable is shown.
    await waitFor(() => expect(categories).toHaveBeenCalled());

    expect(screen.queryByRole("button", { name: "أوافق على ما سبق" })).toBeNull();
    expect(screen.queryByText("ما نجمعه ولا تعمل الخدمة بدونه")).toBeNull();
  });

  /*
   * FR-024, on the same screen rather than another one.
   *
   * Someone reading about their child's recording should not have to go looking
   * for who stores it — and the honest answer includes a processor that CANNOT
   * delete on request.
   */
  it("lists third-party processors with what each can erase", async () => {
    categories.mockResolvedValue({
      data: [RECORDING],
      processors: [
        {
          key: "whatsapp",
          name: "WhatsApp Business",
          purpose: "يوصل الرسائل إلى هاتفك.",
          processing_location: "خوادم المزوّد خارج قطر",
          categories: ["contact_phone"],
          erasure_capability: "none" as const,
          erasure_capability_label_ar: "لا يستقبل طلبَ حذف",
        },
      ],
    } as never);

    render(<ConsentScreen />);

    await waitFor(() => expect(screen.getByText("WhatsApp Business")).not.toBeNull());
    expect(screen.getByText(/لا يستقبل طلبَ حذف/)).not.toBeNull();
  });
});
