import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { LegalDraft } from "./LegalDraft";

/*
| A legal page that reads like binding terms carries the draft banner until legal
| approves it — and its contact lines come from the platform settings, with an
| empty setting dropping its line rather than printing a label with nothing after.
*/

const Icon = () => null;

function draft(supportWhatsapp: string) {
  return render(
    <LegalDraft
      icon={Icon}
      image="/x.webp"
      title="الشروط والأحكام"
      summary="ملخّص"
      updatedAt="٢٦ سبتمبر ٢٠٢٦"
      platformName="المتميّز"
      supportWhatsapp={supportWhatsapp}
    >
      <p>نصّ البند</p>
    </LegalDraft>,
  );
}

describe("LegalDraft", () => {
  it("says it is a draft under legal review", () => {
    draft("");

    expect(screen.getByText("مسودة — قيد المراجعة القانونية")).toBeDefined();
    expect(screen.getByText("نصّ البند")).toBeDefined();
    expect(screen.getByText(/آخر تحديث: ٢٦ سبتمبر ٢٠٢٦/)).toBeDefined();
  });

  it("names the platform from the settings", () => {
    draft("");

    expect(screen.getByText("المتميّز")).toBeDefined();
  });

  it("drops the support line when no number is set", () => {
    draft("");

    expect(screen.queryByText(/واتساب الدعم/)).toBeNull();
  });

  it("prints the support number when one is set", () => {
    draft("97455501234");

    expect(screen.getByText("+97455501234")).toBeDefined();
  });
});
