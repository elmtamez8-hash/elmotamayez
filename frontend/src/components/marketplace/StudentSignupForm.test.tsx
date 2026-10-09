import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { StudentSignupForm } from "./StudentSignupForm";
import type { SchoolYearOption, Taxonomy } from "@/lib/public-api";

/*
| THE FIELD IS FILLED FROM WHAT IT IS HANDED, AND NOTHING ELSE (spec 022 · US1).
|
| The defect this guards is not a rendering bug — it was an empty list arriving
| from the server, because the page fetched the MARKETPLACE read, which drops
| every entry with no publicly listed teacher. On a platform with nobody
| approved yet, the required picker had zero options and no student could
| register at all. What a component test can hold is the other half: that the
| screen renders the whole list it is given and defaults to a real value rather
| than to a placeholder — a blank default is a 422 waiting for anybody who does
| not notice a select they were not asked to touch.
*/

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

/*
| ⚠️ `importOriginal`, NOT A BARE STUB. `homePathFor` is a pure function this
| form calls for its destination; replacing the whole module with `{ useAuth }`
| would hand it `undefined` and the failure would name the router, not the mock.
| Only the hook is stood in for — there is no provider around a bare render.
*/
vi.mock("@/lib/auth-context", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/auth-context")>()),
  useAuth: () => ({ adoptSession: vi.fn() }),
}));

const YEARS: SchoolYearOption[] = [
  { slug: "year-1", name: "الصف الأول الابتدائي", grade_level_slug: "primary" },
  { slug: "year-7", name: "الصف السابع", grade_level_slug: "preparatory" },
  { slug: "year-10", name: "الصف العاشر", grade_level_slug: "secondary" },
];

const REGIONS: Taxonomy[] = [
  { slug: "doha", name: "الدوحة" },
  { slug: "al-rayyan", name: "الريان" },
];

/** Fill step one and press «التالي» — every field below lives on step two. */
function toStepTwo(container: HTMLElement, email = "salma@example.com") {
  fireEvent.change(screen.getByLabelText(/^الاسم الأول/), { target: { value: "سلمى" } });
  fireEvent.change(screen.getByLabelText(/^البريد الإلكتروني/), { target: { value: email } });
  fireEvent.change(container.querySelector("#phone") as HTMLInputElement, { target: { value: "55512345" } });
  fireEvent.change(container.querySelector("#password") as HTMLInputElement, { target: { value: "secret-123" } });
  fireEvent.click(screen.getByRole("button", { name: "التالي" }));
}

describe("StudentSignupForm", () => {
  it("renders every school year it is handed", () => {
    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);
    toStepTwo(container);

    const select = screen.getByLabelText(/^الصف الدراسي/) as HTMLSelectElement;

    // The years, after the one disabled «اختر الصف» placeholder.
    expect(select.options.length).toBe(YEARS.length + 1);
    expect(Array.from(select.options).map((option) => option.value)).toEqual([
      "",
      "year-1",
      "year-7",
      "year-10",
    ]);
  });

  /*
  | ⚠️ REVERSED 2026-10-09 (owner audit). This used to assert the FIRST year,
  | so that nobody hit a 422 on a select they never touched. What it produced
  | instead was a grade nobody chose — every careless signup was a kindergarten
  | pupil with a kindergarten catalogue. The 422 is no longer a trap: the form
  | now scrolls to and focuses the first refused field.
  */
  it("starts with no year chosen, so the grade is one the student picked", () => {
    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);
    toStepTwo(container);

    const select = screen.getByLabelText(/^الصف الدراسي/) as HTMLSelectElement;

    expect(select.value).toBe("");
  });

  it("asks for the year and not for the broad stage", () => {
    // FR-001ج — one question, one stored answer. A form that asked both would
    // be storing two facts that part company at the first edit of the mapping.
    render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);

    expect(screen.queryByLabelText("المرحلة الدراسية")).toBeNull();
  });
});

/*
| Spec 013 — a minor's account IS created, and the sign-in after it refuses on
| purpose until a guardian consents. Rendered as an error it read «try again»,
| and trying again answers 422 on the email the first attempt already took.
*/
const registerStudent = vi.hoisted(() => vi.fn());

vi.mock("@/lib/api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api")>();

  return { ...actual, auth: { ...actual.auth, registerStudent } };
});

describe("StudentSignupForm — a minor awaiting consent", () => {
  it("says the account exists and who has to act, instead of an error", async () => {
    const { ApiError } = await import("@/lib/api");

    registerStudent.mockRejectedValueOnce(
      new ApiError("pending", 403, { code: "pending_guardian_consent", message: "x" }),
    );

    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);
    toStepTwo(container);

    fireEvent.click(container.querySelector("#terms_accepted") as HTMLInputElement);
    fireEvent.submit(container.querySelector("form") as HTMLFormElement);

    expect(await screen.findByText("أُنشئ حسابك، وهو بانتظار موافقة وليّ أمرك")).toBeTruthy();
    expect(screen.queryByRole("alert")).toBeNull();
  });
});

/*
| Spec 011 · FR-018 — the «كود الإحالة» field. Until it existed no screen sent
| `referral_code`, so not one referral had ever been captured.
*/
describe("StudentSignupForm — the referral code", () => {
  function submit(container: HTMLElement) {
    if (container.querySelector("#terms_accepted") === null) toStepTwo(container);
    fireEvent.click(container.querySelector("#terms_accepted") as HTMLInputElement);
    fireEvent.submit(container.querySelector("form") as HTMLFormElement);
  }

  it("prefills the field from the invitation link's code", () => {
    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} referralCode="FRIEND23" />);
    toStepTwo(container);

    // Open already: the link put a code in it.
    expect((screen.getByLabelText("كود الدعوة (اختياري)") as HTMLInputElement).value).toBe("FRIEND23");
  });

  it("sends the code when the field is filled, upper-cased", () => {
    registerStudent.mockReset();
    registerStudent.mockReturnValueOnce(new Promise(() => {}));

    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);
    toStepTwo(container);

    // Behind a link when nobody prefilled it.
    expect(screen.queryByLabelText("كود الدعوة (اختياري)")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "عندك كود دعوة من صديق؟" }));
    fireEvent.change(screen.getByLabelText("كود الدعوة (اختياري)"), { target: { value: " friend23 " } });
    submit(container);

    expect(registerStudent).toHaveBeenCalledTimes(1);
    expect(registerStudent.mock.calls[0][0].referral_code).toBe("FRIEND23");
  });

  it("leaves the key out entirely when the field is empty", () => {
    registerStudent.mockReset();
    registerStudent.mockReturnValueOnce(new Promise(() => {}));

    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);

    submit(container);

    expect(registerStudent).toHaveBeenCalledTimes(1);
    expect(JSON.stringify(registerStudent.mock.calls[0][0])).not.toContain("referral_code");
  });

  it("shows a 422 on the code under the field itself, not in the banner", async () => {
    const { ApiError } = await import("@/lib/api");
    const sentence = "لم نجد هذا الكود. تأكّد منه، أو امسح الخانة وأكمل التسجيل بدونه.";

    registerStudent.mockReset();
    registerStudent.mockRejectedValueOnce(
      new ApiError("invalid", 422, { message: "invalid", errors: { referral_code: [sentence] } }),
    );

    const { container } = render(
      <StudentSignupForm schoolYears={YEARS} regions={REGIONS} referralCode="NOSUCH99" />,
    );

    submit(container);

    const message = await screen.findByText(sentence);

    expect(message.id).toBe("referral_code-error");
    expect(screen.getByLabelText("كود الدعوة (اختياري)").getAttribute("aria-invalid")).toBe("true");
    expect(screen.queryByRole("alert")).toBeNull();
  });
});

/*
| The shorter form (owner decision 2026-10-09): two steps, no password twice,
| the country taken from the phone's dial code, the invite code behind a link.
*/
describe("StudentSignupForm — two steps", () => {
  it("keeps step one until its fields are filled, and says which", () => {
    registerStudent.mockReset();
    render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);

    fireEvent.click(screen.getByRole("button", { name: "التالي" }));

    expect(screen.getByText("أدخل اسمك الأول.")).toBeDefined();
    expect(screen.getByText("كلمة المرور ثمانية أحرف على الأقل.")).toBeDefined();
    expect(screen.queryByLabelText(/^الصف الدراسي/)).toBeNull();
    expect(registerStudent).not.toHaveBeenCalled();
  });

  it("asks for the password once and the country never — it is the phone's", () => {
    registerStudent.mockReset();
    registerStudent.mockReturnValueOnce(new Promise(() => {}));

    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);

    expect(container.querySelector("#password_confirmation")).toBeNull();
    expect(screen.queryByLabelText("الدولة")).toBeNull();

    toStepTwo(container);
    fireEvent.click(container.querySelector("#terms_accepted") as HTMLInputElement);
    fireEvent.submit(container.querySelector("form") as HTMLFormElement);

    const sent = registerStudent.mock.calls[0][0];
    expect(sent.country).toBe("QA");
    expect(sent.phone).toBe("+97455512345");
    expect("password_confirmation" in sent).toBe(false);
  });

  it("goes back to step one when the server refuses the email", async () => {
    const { ApiError } = await import("@/lib/api");

    registerStudent.mockReset();
    registerStudent.mockRejectedValueOnce(
      new ApiError("invalid", 422, { message: "invalid", errors: { email: ["هذا البريد مسجّل من قبل."] } }),
    );

    const { container } = render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);
    toStepTwo(container);
    fireEvent.click(container.querySelector("#terms_accepted") as HTMLInputElement);
    fireEvent.submit(container.querySelector("form") as HTMLFormElement);

    expect(await screen.findByText("هذا البريد مسجّل من قبل.")).toBeDefined();
    expect(screen.getByLabelText(/^البريد الإلكتروني/).getAttribute("aria-invalid")).toBe("true");
    // …and what was typed on step two is still there behind «التالي».
    fireEvent.click(screen.getByRole("button", { name: "التالي" }));
    expect((container.querySelector("#terms_accepted") as HTMLInputElement).checked).toBe(true);
  });
});
