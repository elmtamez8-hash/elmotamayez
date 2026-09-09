import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CertificateTab } from "./CertificateTab";
import type { Certificate } from "@/lib/types";

/*
| «افتح شهادتك» كان يذهبُ إلى الرفِّ لا إلى الشهادة.
|
| العنوانُ كان `` href={`/certificates`} `` — قالبَ نصٍّ لا يُدرَجُ فيه شيء، وهو
| شكلُ سطرٍ لم يكتمل. فطالبٌ يقرأُ «شهادتك في هذه المادّة» ورقمُها مطبوعٌ فوقَ
| الزرِّ، يضغطُ فيهبطُ على فهرسِ كلِّ شهاداتِه ليبحثَ عن هذه بيدِه.
|
| ⚠️ والاختبارُ يسألُ عن **الوجهة**، لا عن وجودِ الزرّ. زرٌّ موجودٌ يشيرُ إلى
| المكانِ الخطأ يمرُّ في كلِّ لقطةِ شاشةٍ وفي أيِّ تأكيدٍ يسألُ «هل الزرُّ هنا؟»
| — وهو بالضبطِ ما لم يُمسَكْ حتّى فُتِحَت الصفحةُ باليد.
*/
const CERTIFICATE = {
  uuid: "cert-1",
  certificate_number: "CERT-2026-C9RPWNCD",
  verification_code: "8DBgEOCMWcztOKZ524YsWv7ZkF8u6aKqDAi0gc4A",
  issue_reason: "course_completed",
  issued_at: "2026-09-06T12:09:56Z",
  course_title: "أساسيّات التفاضل",
  student_name: "Demo Student",
} as Certificate;

describe("CertificateTab", () => {
  it("opens THIS certificate, never the list of all of them", () => {
    render(
      <CertificateTab
        certificate={CERTIFICATE}
        completedCount={10}
        countableCount={10}
        progressPct={100}
      />,
    );

    const link = screen.getByRole("link", { name: "افتح شهادتك" });

    expect(link.getAttribute("href")).toBe(
      `/certificates/verify/${CERTIFICATE.verification_code}`,
    );
  });

  it("names what is left to do when no certificate has been issued", () => {
    // النصفُ الثاني هو المطلوب: «لا شهادة» وحدَها طريقٌ مسدود، والطالبُ يفتحُ
    // هذا التبويبَ ليعرفَ ما بقيَ عليه.
    render(
      <CertificateTab
        certificate={null}
        completedCount={7}
        countableCount={10}
        progressPct={70}
      />,
    );

    expect(screen.getByText("لم تصدر شهادتك بعد")).toBeTruthy();
    expect(screen.queryByRole("link", { name: "افتح شهادتك" })).toBeNull();
  });
});
