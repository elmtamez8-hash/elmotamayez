"use client";

import { useCallback, useEffect, useState } from "react";

import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { counted, formatDate } from "@/lib/labels";
import { can, P } from "@/lib/permissions";
import { matchesSearch } from "@/lib/search-text";
import type { Certificate } from "@/lib/types";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { FilterBar } from "@/components/ui/FilterBar";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatStrip } from "@/components/ui/StatStrip";
import { StatTile } from "@/components/ui/StatTile";
import { EmptyState } from "@/components/ui/states/EmptyState";
import {
  CertificateIcon,
  IssuedDateIcon,
  PrintIcon,
  RefreshIcon,
  VerifiedIcon,
} from "@/components/icons";
import { Table, type Column } from "@/components/ui/Table";

/** «١٢ شهادة» — built with `counted()`, never a template literal. */
const CERTIFICATES = { one: "شهادة واحدة", two: "شهادتان", few: "شهادات", many: "شهادةً", other: "شهادة" };

/**
 * The certificates a teacher's students earned. Their own are at `/certificates`.
 *
 * ⚠️ THIS SCREEN IS THE DEFECT, NOT THE RENAME. `CertificateController::index()`
 * widens to every certificate in the workspace for a holder of
 * `certificates.view.all` — so a teacher opening «الشهادات» was already reading
 * their students' records, under the heading «شهاداتي», in a card layout that
 * printed no student name anywhere, over an empty state offering «أكمل كورساً
 * لتحصل على أولى شهاداتك». `student_name` sat in the payload unrendered for the
 * life of that screen.
 *
 * ⚠️ AND IT IS `student_name`, WHICH IS A FROZEN COLUMN, NOT A LIVE JOIN. The
 * resource says why: the same payload answers the PUBLIC verification route, so
 * the name is what it was on the day it was earned — an anonymised account must
 * not rewrite a public statement of fact, and a severed one must not make the
 * certificate verify as belonging to nobody.
 *
 * ⚠️ AND «عرض المزيد» IS NOT DECORATION. The endpoint paginates at fifteen and
 * takes no page size; a teacher with a second year of students would otherwise
 * read a list that stops without saying so, which is the shape of a screen that
 * lies rather than one that is merely short.
 *
 * ⚠️ THE SEARCH READS THE ROWS ON SCREEN, AND THE BAR SAYS SO. The endpoint takes
 * no `q`, so a match is looked for in the pages already loaded; while a page is
 * left, the summary names that limit and «عرض المزيد» stays under the list — a
 * search answering «no match» over the first fifteen would be the screen that
 * lies, again. The strip's figures are the server's own: `meta.total`, and the
 * newest row's date (the endpoint orders by `issued_at` descending).
 */
export default function ManageCertificatesPage() {
  const { user } = useAuth();
  // `certificates.regenerate` at the door (CertificatePolicy::regenerate); the
  // button is not offered to a reader the server would refuse.
  const canRegenerate = can(user, P.certificatesRegenerate);

  const [certificates, setCertificates] = useState<Certificate[]>([]);
  const [regenerating, setRegenerating] = useState<Certificate | null>(null);
  const [regenerateBusy, setRegenerateBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [total, setTotal] = useState<number | null>(null);
  const [query, setQuery] = useState("");

  const load = useCallback((target: number) => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Certificate[]; meta?: { last_page: number; total?: number } }>(`/certificates?page=${target}`)
      .then((res) => {
        // Appended, never replaced — «عرض المزيد» that swaps the list out is a
        // button that loses the rows the reader was looking at.
        setCertificates((rows) => (target === 1 ? (res.data ?? []) : [...rows, ...(res.data ?? [])]));
        setLastPage(res.meta?.last_page ?? 1);
        setTotal(res.meta?.total ?? null);
        setPage(target);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => load(1), [load]);

  /*
   | ⚠️ WHAT THIS DOES IS SMALLER THAN ITS NAME. The certificate is DRAWN live
   | from the current design every time it is opened, so a changed design
   | already reaches every certificate without this button. Regenerating keeps
   | the number, the verification code and the issue date exactly as they were
   | (the Action refuses to restamp the date — that would be forgery), records
   | the act in the audit log and tells the student. The confirmation says so,
   | rather than promising a new file.
   */
  const regenerate = async () => {
    if (regenerating === null) return;

    setRegenerateBusy(true);
    setNotice(null);

    try {
      await api.post(`/certificates/${regenerating.uuid}/regenerate`);
      setNotice({
        tone: "success",
        text: `أُعيد إصدار شهادة ${regenerating.student_name ?? "الطالب"} ووصله إشعار بذلك.`,
      });
    } catch (err: unknown) {
      setNotice({ tone: "danger", text: userMessage(err) });
    } finally {
      setRegenerateBusy(false);
      setRegenerating(null);
    }
  };

  const shown = certificates.filter((row) =>
    matchesSearch(query, row.student_name, row.certificate_number, row.course_title),
  );
  const more = page < lastPage;
  const tableState =
    loading && certificates.length === 0
      ? "loading"
      : failed
        ? "error"
        : certificates.length === 0
          ? "empty"
          : "ready";

  const columns: Column<Certificate>[] = [
    {
      key: "student",
      header: "الطالب",
      // The column this screen exists for. It was in the payload and on no page.
      render: (row) => <span className="font-medium text-ink">{row.student_name ?? "—"}</span>,
    },
    {
      key: "course",
      header: "الكورس",
      render: (row) => row.course_title ?? "—",
    },
    {
      key: "number",
      header: "رقم الشهادة",
      render: (row) => <bdi className="text-ink-muted">{row.certificate_number}</bdi>,
    },
    {
      key: "issued",
      header: "تاريخ الإصدار",
      render: (row) => formatDate(row.issued_at),
    },
    {
      key: "verify",
      header: "الإجراء",
      render: (row) => (
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            href={`/certificates/verify/${row.verification_code}`}
            iconStart={<VerifiedIcon />}
          >
            تحقّق
          </Button>
          {canRegenerate && (
            <Button variant="ghost" size="sm" iconStart={<RefreshIcon />} onClick={() => setRegenerating(row)}>
              أعِد الإصدار <span className="sr-only">{`لشهادة ${row.student_name ?? row.certificate_number}`}</span>
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-8">
      {/*
        ⚠️ THE ONLY WAY IN. `/manage/certificates/design` is reachable from
        nowhere else, and a screen nobody can reach is not shipped (SC-009) —
        which is the exact defect this whole feature was born from.
      */}
      <PageHeader
        Icon={CertificateIcon}
        title="شهادات الطلاب"
        description="كلّ شهادة صدرت عندك، والاسم عليها هو الاسم يوم استحقّها."
        actions={
          // The design screen asks `certificates.regenerate` for every read.
          canRegenerate ? (
            <Button variant="secondary" href="/manage/certificates/design" iconStart={<PrintIcon />}>
              تصميم الشهادة
            </Button>
          ) : undefined
        }
      />

      {tableState === "ready" && (
        <StatStrip label="ملخّص الشهادات" columns={2}>
          <StatTile
            label="الشهادات الصادرة"
            value={total ?? certificates.length}
            Icon={CertificateIcon}
            emphasis
          />
          <StatTile
            label="آخر شهادة صدرت"
            value={formatDate(certificates[0].issued_at)}
            Icon={IssuedDateIcon}
          />
        </StatStrip>
      )}

      <section aria-labelledby="student-certificates" className="space-y-4">
        <SectionHeading id="student-certificates" Icon={CertificateIcon} title="الشهادات" />

        {notice !== null && <Alert tone={notice.tone} title={notice.text} />}

        {tableState === "ready" && (
          <FilterBar
            search={{
              id: "certificate-search",
              label: "ابحث في الشهادات",
              value: query,
              onChange: setQuery,
              placeholder: "اسم الطالب أو رقم الشهادة أو الكورس",
            }}
            summary={
              query === ""
                ? counted(total ?? certificates.length, CERTIFICATES)
                : more
                  ? `${counted(shown.length, CERTIFICATES)} فيما حُمِّل حتى الآن`
                  : counted(shown.length, CERTIFICATES)
            }
          />
        )}

        {tableState === "ready" && shown.length === 0 ? (
          <EmptyState
            title="لا شهادة تطابق البحث"
            description={
              more
                ? "لا شيء فيما حُمِّل حتى الآن يطابق ما كتبته. حمّل المزيد أدناه، أو جرّب اسماً أو رقماً آخر."
                : "لا شهادة تطابق ما كتبته. جرّب اسماً أو رقماً آخر."
            }
            action={
              <Button variant="secondary" size="sm" onClick={() => setQuery("")}>
                مسح البحث
              </Button>
            }
          />
        ) : (
          <Table
            columns={columns}
            rows={shown}
            rowKey={(row) => row.uuid}
            caption="شهادات طلابك بأسمائهم وكورساتهم وتواريخ إصدارها"
            state={tableState}
            emptyIcon={CertificateIcon}
            emptyTitle="لا شهادات بعد"
            /* Course completion alone issues it (owner decision 2026-09-25); a
               passed exam counts only by completing its item in the course. */
            emptyDescription="تصدر الشهادة تلقائياً حين يُكمل طالبٌ كلّ عناصر كورسه. جهّز شكلها من «تصميم الشهادة» أعلاه."
            onRetry={() => load(1)}
          />
        )}

        {more && (
          <div className="flex justify-center">
            <Button
              variant="secondary"
              loading={loading}
              loadingLabel="جارٍ التحميل…"
              onClick={() => load(page + 1)}
            >
              عرض المزيد
            </Button>
          </div>
        )}
      </section>

      <Modal
        open={regenerating !== null}
        title="إعادة إصدار الشهادة"
        message={`يصل ${regenerating?.student_name ?? "الطالب"} إشعارٌ بأن شهادته أُعيد إصدارها بالتصميم الحالي. رقم الشهادة ورمز التحقّق وتاريخ الإصدار لا تتغيّر.`}
        confirmLabel="أعِد الإصدار"
        busy={regenerateBusy}
        onConfirm={() => void regenerate()}
        onCancel={() => setRegenerating(null)}
      />
    </div>
  );
}
