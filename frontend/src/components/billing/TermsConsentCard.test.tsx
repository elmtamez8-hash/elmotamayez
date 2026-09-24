import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import type { ConsentState } from "@/lib/billing";

/*
| الموافقةُ على شروطِ الدفعِ المؤجَّلِ — للطالبِ نفسِه، ولوليِّ الأمرِ نيابةً عن ابنِه.
|
| ⚠️ النداءُ هو ما يُؤكَّدُ، لا النصُّ وحدَه. بطاقةٌ تقولُ «نيابةً عن ابنك» وتُرسِلُ
| الطلبَ بلا `student` تُسجِّلُ وليَّ الأمرِ مَديناً عن نفسِه — وهو بالضبطِ ما أُزيلَت
| من أجلِه البطاقةُ القديمةُ من `/billing` وليِّ الأمر. فكلُّ حالةٍ هنا تسألُ بأيِّ
| معرِّفٍ نودِيَ الخادم.
*/

const consents = vi.fn();
const accept = vi.fn();

vi.mock("@/lib/billing", () => ({
  billing: {
    consents: (studentUuid?: string) => consents(studentUuid),
    accept: (document: string, studentUuid?: string) => accept(document, studentUuid),
  },
}));

import { TermsConsentCard } from "@/components/billing/TermsConsentCard";

function doc(overrides: Partial<ConsentState> = {}): ConsentState {
  return {
    document: "deferred_payment_terms",
    label: "شروط الدفع المؤجَّل",
    version: "1.0",
    consented_at: null,
    ...overrides,
  };
}

const child = { uuid: "child-1", name: "كريم" };

beforeEach(() => {
  consents.mockReset();
  accept.mockReset();
});

describe("TermsConsentCard for a child", () => {
  it("reads the child's state and shows the version and the guardian wording", async () => {
    consents.mockResolvedValue({ data: [doc({ version: "2.1" })] });

    render(<TermsConsentCard student={child} />);

    expect(await screen.findByText("أوافق نيابةً عن كريم")).toBeTruthy();
    expect(consents).toHaveBeenCalledWith("child-1");
    expect(screen.getByText("2.1")).toBeTruthy();
    expect(screen.getByText(/نيابةً عن ابنك/)).toBeTruthy();
  });

  it("signs FOR the child, then disappears and tells the dashboard", async () => {
    consents.mockResolvedValue({ data: [doc()] });
    accept.mockResolvedValue({ data: [doc({ consented_at: "2026-09-25T10:00:00Z" })] });
    const onAccepted = vi.fn();

    render(<TermsConsentCard student={child} onAccepted={onAccepted} />);

    fireEvent.click(await screen.findByText("أوافق نيابةً عن كريم"));

    await waitFor(() => expect(onAccepted).toHaveBeenCalledTimes(1));
    // ⚠️ The child's uuid travels with the signature — without it the server
    // records the guardian as the one who owes.
    expect(accept).toHaveBeenCalledWith("deferred_payment_terms", "child-1");
    expect(screen.queryByText("أوافق نيابةً عن كريم")).toBeNull();
  });

  it("renders nothing when the child has already accepted the current version", async () => {
    consents.mockResolvedValue({ data: [doc({ consented_at: "2026-09-01T10:00:00Z" })] });

    const { container } = render(<TermsConsentCard student={child} />);

    await waitFor(() => expect(consents).toHaveBeenCalled());
    expect(container.innerHTML).toBe("");
  });

  it("renders nothing when the guardian lacks the permission (the server's 403)", async () => {
    consents.mockRejectedValue(new Error("403"));

    const { container } = render(<TermsConsentCard student={child} />);

    await waitFor(() => expect(consents).toHaveBeenCalled());
    expect(container.innerHTML).toBe("");
  });

  it("never offers a guardian the data-processing document from this card", async () => {
    consents.mockResolvedValue({
      data: [doc({ document: "data_processing", label: "معالجة البيانات" })],
    });

    const { container } = render(<TermsConsentCard student={child} />);

    await waitFor(() => expect(consents).toHaveBeenCalled());
    expect(container.innerHTML).toBe("");
  });
});

describe("TermsConsentCard for the student themselves", () => {
  it("asks and signs with no student at all", async () => {
    consents.mockResolvedValue({ data: [doc()] });
    accept.mockResolvedValue({ data: [doc({ consented_at: "2026-09-25T10:00:00Z" })] });

    render(<TermsConsentCard />);

    fireEvent.click(await screen.findByText("أوافق على شروط الدفع المؤجَّل"));

    await waitFor(() => expect(accept).toHaveBeenCalledWith("deferred_payment_terms", undefined));
    expect(consents).toHaveBeenCalledWith(undefined);
  });
});
