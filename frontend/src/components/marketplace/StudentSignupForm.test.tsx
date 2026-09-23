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

describe("StudentSignupForm", () => {
  it("renders every school year it is handed", () => {
    render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);

    const select = screen.getByLabelText("الصف الدراسي") as HTMLSelectElement;

    expect(select.options.length).toBe(YEARS.length);
    expect(Array.from(select.options).map((option) => option.value)).toEqual([
      "year-1",
      "year-7",
      "year-10",
    ]);
  });

  it("defaults to a real year rather than to an empty placeholder", () => {
    render(<StudentSignupForm schoolYears={YEARS} regions={REGIONS} />);

    const select = screen.getByLabelText("الصف الدراسي") as HTMLSelectElement;

    expect(select.value).toBe("year-1");
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

    fireEvent.click(container.querySelector("#terms_accepted") as HTMLInputElement);
    fireEvent.submit(container.querySelector("form") as HTMLFormElement);

    expect(await screen.findByText("أُنشئ حسابك، وهو بانتظار موافقة وليّ أمرك")).toBeTruthy();
    expect(screen.queryByRole("alert")).toBeNull();
  });
});
