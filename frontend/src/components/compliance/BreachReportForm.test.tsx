import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

import { BREACH_REPORT_RECEIVED, BreachReportForm } from "./BreachReportForm";

/**
 * FR-040 — the public breach form tells the reporter nothing but «received».
 *
 * ⚠️ THE CONFIRMATION IS ASSERTED AGAINST A SERVER BODY THAT SAYS SOMETHING ELSE.
 * A form that echoed the response would pass a test whose mock returned the
 * expected sentence; here the mock returns a body naming a record, and the screen
 * must still show the constant.
 */
const compliance = vi.hoisted(() => ({ reportBreach: vi.fn() }));

vi.mock("@/lib/compliance", () => ({ compliance }));

function fill(description: string, contact = "") {
  fireEvent.change(screen.getByLabelText(/ماذا رأيت/), { target: { value: description } });
  if (contact !== "") {
    fireEvent.change(screen.getByLabelText(/وسيلة للتواصل/), { target: { value: contact } });
  }
  fireEvent.click(screen.getByRole("button", { name: "أرسل البلاغ" }));
}

describe("BreachReportForm", () => {
  beforeEach(() => {
    compliance.reportBreach.mockReset();
  });

  it("sends the trimmed report and shows the constant confirmation, never the server's body", async () => {
    compliance.reportBreach.mockResolvedValue({ message: "breach_report 42 already known", uuid: "r-1" });

    render(<BreachReportForm />);
    fill("  روابط تسجيلات تُفتح بلا تسجيل دخول من محرّك بحث.  ", " researcher@example.org ");

    expect(await screen.findByText(BREACH_REPORT_RECEIVED)).toBeTruthy();
    expect(compliance.reportBreach).toHaveBeenCalledWith({
      description: "روابط تسجيلات تُفتح بلا تسجيل دخول من محرّك بحث.",
      reporter_contact: "researcher@example.org",
    });
    expect(screen.queryByText(/breach_report|already known|r-1/)).toBeNull();
  });

  it("omits an empty contact rather than sending a blank one", async () => {
    compliance.reportBreach.mockResolvedValue({ message: "x" });

    render(<BreachReportForm />);
    fill("وصفٌ كافٍ لحادثٍ مزعومٍ بلا وسيلة تواصل.");

    await screen.findByText(BREACH_REPORT_RECEIVED);
    expect(compliance.reportBreach.mock.calls[0][0]).not.toHaveProperty("reporter_contact");
  });

  it("shows the limiter's refusal in Arabic, never the framework's English", async () => {
    compliance.reportBreach.mockRejectedValue(
      new ApiError("Too Many Attempts.", 429, { message: "Too Many Attempts." }),
    );

    render(<BreachReportForm />);
    fill("وصفٌ كافٍ لحادثٍ مزعومٍ يصطدم بالحدّ.");

    expect(await screen.findByText(/محاولات كثيرة في وقت قصير/)).toBeTruthy();
    expect(screen.queryByText(/Too Many Attempts/)).toBeNull();
    expect(screen.queryByText(BREACH_REPORT_RECEIVED)).toBeNull();
  });

  it("hides a server failure's raw text behind a sentence", async () => {
    compliance.reportBreach.mockRejectedValue(
      new ApiError("SQLSTATE[HY000]: General error", 500, { message: "Server Error" }),
    );

    render(<BreachReportForm />);
    fill("وصفٌ كافٍ لحادثٍ مزعومٍ يصطدم بعطل.");

    expect(await screen.findByText(/حدث خطأ لدينا/)).toBeTruthy();
    expect(screen.queryByText(/SQLSTATE|Server Error/)).toBeNull();
  });

  it("puts a too-short description's message under its field", async () => {
    compliance.reportBreach.mockRejectedValue(
      new ApiError("صِفِ الحادثَ بما يكفي لفحصه — ماذا رأيتَ وأين.", 422, {
        message: "صِفِ الحادثَ بما يكفي لفحصه — ماذا رأيتَ وأين.",
        errors: { description: ["صِفِ الحادثَ بما يكفي لفحصه — ماذا رأيتَ وأين."] },
      }),
    );

    render(<BreachReportForm />);
    fill("قصير");

    await waitFor(() =>
      expect(screen.getByLabelText(/ماذا رأيت/).getAttribute("aria-invalid")).toBe("true"),
    );
    expect(screen.getByText("صِفِ الحادثَ بما يكفي لفحصه — ماذا رأيتَ وأين.")).toBeTruthy();
    expect(screen.queryByText(BREACH_REPORT_RECEIVED)).toBeNull();
  });
});
