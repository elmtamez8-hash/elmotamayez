"use client";

import { use, useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import { certificateReasonLabel, formatDate } from "@/lib/labels";
import { CertificateArtwork } from "@/components/certificates/CertificateArtwork";
import type { DesignPayload } from "@/lib/certificate-design";
import { Button } from "@/components/ui/Button";
import { userMessage } from "@/lib/errors";
import { downloadBlob, renderCertificateImage } from "@/lib/certificate-image";
import {
  AlertIcon,
  DownloadIcon,
  CertificateNumberIcon,
  IssuedDateIcon,
  StudentIcon,
  PrintIcon,
  SubjectIcon,
  TeacherIcon,
  VerifiedIcon,
} from "@/components/icons";

interface VerifiedCertificate {
  certificate_number: string;
  verification_code: string;
  issue_reason: string;
  issued_at: string;
  course_title: string | null;
  student_name: string | null;
  teacher_name: string | null;
  subject_name: string | null;
  design: DesignPayload;
}

interface VerifyResponse {
  valid: boolean;
  certificate?: VerifiedCertificate;
}

export default function VerifyCertificatePage({
  params,
}: {
  params: Promise<{ code: string }>;
}) {
  const { code } = use(params);
  const [data, setData] = useState<VerifyResponse | null>(null);
  const [loading, setLoading] = useState(true);
  /*
    The code carries the ABSOLUTE address of this page (FR-028) — a phone camera
    resolves nothing relative. It is read after mount rather than during render
    because the server has no `window`, and a value that differs between the two
    renders is a hydration mismatch.
  */
  const [pageUrl, setPageUrl] = useState("");
  /*
    ⚠️ THE PAPER FOLLOWS THE ARTWORK (`FR-047`). Both shipped templates are
    landscape and an uploaded one may not be, so the orientation is read off the
    image that actually loaded rather than assumed — a landscape certificate on a
    portrait page loses a third of itself to the margin. It defaults to landscape
    because that is what the product ships; a wrong guess for one frame costs
    nothing, since printing happens long after the image has loaded.
  */
  const [landscape, setLandscape] = useState(true);
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);
  const frame = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setPageUrl(window.location.href);
  }, []);

  useEffect(() => {
    api
      .get<VerifyResponse>(`/certificates/verify/${code}`)
      .then(setData)
      // A failed lookup and an invalid code are the same answer to the person
      // checking: this certificate cannot be confirmed.
      .catch(() => setData({ valid: false }))
      .finally(() => setLoading(false));
  }, [code]);

  /*
    ⚠️ THE PICTURE IS DRAWN, NOT SCREENSHOTTED. It goes through the same fitting
    functions the page draws with, at the export's own dimensions — every position
    is a fraction of the image, so the file is the page at a larger scale and the
    two cannot drift apart. A DOM-to-image library would be a second renderer with
    its own opinion about where a name sits.

    ⚠️ And the FONT is the resolved family off the artwork itself: a canvas cannot
    resolve `var(--font-sans)` and silently keeps `10px sans-serif` when handed
    one, which is a certificate with the writing a tenth of its size.
  */
  const download = useCallback(async () => {
    const certificate = data?.certificate;

    if (certificate === undefined) return;

    setDownloading(true);
    setDownloadError(null);

    try {
      const family =
        frame.current === null ? "sans-serif" : getComputedStyle(frame.current).fontFamily;

      const { blob } = await renderCertificateImage(
        certificate.design,
        {
          student: certificate.student_name ?? "",
          subject: certificate.subject_name ?? certificate.course_title ?? "",
          teacher: certificate.teacher_name ?? "",
          date: formatDate(certificate.issued_at),
          number: certificate.certificate_number,
          verifyUrl: pageUrl,
        },
        family,
      );

      downloadBlob(blob, `${certificate.certificate_number}.jpg`);
    } catch (cause) {
      // Never a raw error, and never a silent no-op on a button somebody pressed.
      setDownloadError(userMessage(cause));
    } finally {
      setDownloading(false);
    }
  }, [data, pageUrl]);

  if (loading) {
    return (
      <main
        id="main"
        className="flex min-h-[60vh] items-center justify-center text-ink-muted"
      >
        جارٍ التحقّق…
      </main>
    );
  }

  if (!data?.valid || !data.certificate) {
    /*
      ⚠️ A REFUSAL DRAWS NO CERTIFICATE — no artwork, no name, not even an empty
      frame (FR-034). A sheet with blank fields reads as "the details failed to
      load", which is the opposite of what happened.
    */
    return (
      <main id="main" className="flex min-h-[60vh] items-center justify-center px-4 py-10">
        <div
          role="alert"
          className="w-full max-w-md rounded-2xl border border-line bg-surface-raised p-8 text-center"
        >
          <AlertIcon className="mx-auto mb-4 h-12 w-12 text-danger-ink" />
          <h1 className="mb-2 text-xl font-bold text-ink">شهادة غير صالحة</h1>
          <p className="text-ink-muted">
            رمز التحقّق غير موجود أو لم يعد صالحاً. تأكّد من نسخ الرمز كاملاً.
          </p>
        </div>
      </main>
    );
  }

  const certificate = data.certificate;
  const subject = certificate.subject_name ?? certificate.course_title ?? "—";

  return (
    <main id="main" className="mx-auto w-full max-w-4xl px-4 py-10 print:max-w-none print:p-0">
      {/*
        ⚠️ ONE PAGE, THE CERTIFICATE ALONE (`FR-036`). Everything else on this
        screen carries `print:hidden` — the verdict card most of all: printed onto
        the sheet it becomes part of the picture, and a picture is exactly what
        somebody forging one would send.
      */}
      <style>{`@page { size: ${landscape ? "landscape" : "portrait"}; margin: 10mm; }`}</style>
      {/*
        ⚠️ THE VERDICT SITS ABOVE THE ARTWORK AND OUTSIDE IT (FR-003). Printed on
        the sheet it would become part of the picture — and a picture is exactly
        what somebody forging one would send. The platform says "verified"; the
        certificate says who earned what.
      */}
      <div className="mb-6 flex items-center gap-3 rounded-2xl border border-line bg-surface-raised p-5 print:hidden">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-secondary text-white">
          <VerifiedIcon className="h-6 w-6" />
        </span>
        <div>
          <h1 className="text-lg font-bold text-ink">الشهادة موثّقة</h1>
          <p className="text-sm text-ink-muted">
            هذه الشهادة صحيحة وصادرة من المنصة.
          </p>
        </div>
      </div>

      <div ref={frame} className="print:break-inside-avoid">
        <CertificateArtwork
          design={certificate.design}
          onAspect={(aspect) => setLandscape(aspect >= 1)}
          values={{
            student: certificate.student_name ?? "",
            subject: subject,
            teacher: certificate.teacher_name ?? "",
            date: formatDate(certificate.issued_at),
            number: certificate.certificate_number,
            verifyUrl: pageUrl,
          }}
        />
      </div>

      {/*
        Neither button is printed — a sheet carrying a picture of its own «اطبع»
        button is the tell of a screenshot.
      */}
      <div className="mt-4 flex flex-wrap justify-end gap-2 print:hidden">
        <Button
          variant="ghost"
          iconStart={<DownloadIcon className="h-4 w-4" />}
          loading={downloading}
          loadingLabel="جارٍ التحضير…"
          onClick={download}
        >
          نزّل الشهادة صورة
        </Button>
        <Button variant="ghost" iconStart={<PrintIcon className="h-4 w-4" />} onClick={() => window.print()}>
          احفظ أو اطبع
        </Button>
      </div>

      {downloadError !== null && (
        <p className="mt-2 text-end text-sm text-danger-ink print:hidden">{downloadError}</p>
      )}

      {/*
        ⚠️ THE SAME FACTS AGAIN, AS TEXT (FR-004). The artwork is one image away
        from being unreadable — a slow connection, images switched off, a screen
        reader — and the verdict is worthless if the facts it confirms cannot be
        read. This list is the certificate; the sheet above is how it looks.
      */}
      <dl className="mt-6 grid gap-3 rounded-2xl border border-line bg-surface-raised p-6 sm:grid-cols-2 print:hidden">
        <Fact icon={<StudentIcon />} label="الطالب" value={certificate.student_name ?? "—"} />
        <Fact icon={<SubjectIcon />} label="المادة" value={subject} />
        <Fact icon={<TeacherIcon />} label="المدرّس" value={certificate.teacher_name ?? "—"} />
        <Fact
          icon={<IssuedDateIcon />}
          label="تاريخ المنح"
          value={formatDate(certificate.issued_at)}
        />
        <Fact
          icon={<CertificateNumberIcon />}
          label="رقم الشهادة"
          value={certificate.certificate_number}
          numeric
        />
        <Fact
          icon={<VerifiedIcon />}
          label="سبب الإصدار"
          value={certificateReasonLabel(certificate.issue_reason)}
        />
      </dl>
    </main>
  );
}

function Fact({
  icon,
  label,
  value,
  numeric,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  numeric?: boolean;
}) {
  return (
    <div className="flex items-start gap-3 border-b border-line pb-3 last:border-b-0 sm:last:border-b">
      <span className="mt-0.5 text-ink-muted">{icon}</span>
      <div className="min-w-0">
        <dt className="text-xs text-ink-muted">{label}</dt>
        <dd className="truncate font-medium text-ink">
          {numeric ? <bdi>{value}</bdi> : value}
        </dd>
      </div>
    </div>
  );
}
