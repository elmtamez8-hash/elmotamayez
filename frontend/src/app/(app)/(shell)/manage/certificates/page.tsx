"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { api } from "@/lib/api";
import { formatDate } from "@/lib/labels";
import type { Certificate } from "@/lib/types";
import { Button } from "@/components/ui/Button";
import { Table, type Column } from "@/components/ui/Table";

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
 */
export default function ManageCertificatesPage() {
  const [certificates, setCertificates] = useState<Certificate[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback((target: number) => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Certificate[]; meta?: { last_page: number } }>(`/certificates?page=${target}`)
      .then((res) => {
        // Appended, never replaced — «عرض المزيد» that swaps the list out is a
        // button that loses the rows the reader was looking at.
        setCertificates((rows) => (target === 1 ? (res.data ?? []) : [...rows, ...(res.data ?? [])]));
        setLastPage(res.meta?.last_page ?? 1);
        setPage(target);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => load(1), [load]);

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
        <Link
          href={`/certificates/verify/${row.verification_code}`}
          className="text-primary-ink underline-offset-4 hover:underline"
        >
          تحقّق
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start gap-3">
        <div className="me-auto">
          <h1 className="text-xl font-semibold text-ink">شهادات الطلاب</h1>
          <p className="mt-1 text-sm text-ink-muted">
            كلّ شهادة صدرت عندك، والاسم عليها هو الاسم يوم استحقّها.
          </p>
        </div>

        {/*
          ⚠️ THE ONLY WAY IN. `/manage/certificates/design` is reachable from
          nowhere else, and a screen nobody can reach is not shipped (SC-009) —
          which is the exact defect this whole feature was born from.
        */}
        <Button variant="ghost" size="sm" href="/manage/certificates/design">
          تصميم الشهادة
        </Button>
      </header>

      <Table
        columns={columns}
        rows={certificates}
        rowKey={(row) => row.uuid}
        caption="شهادات طلابك بأسمائهم وكورساتهم وتواريخ إصدارها"
        state={
          loading && certificates.length === 0
            ? "loading"
            : failed
              ? "error"
              : certificates.length === 0
                ? "empty"
                : "ready"
        }
        emptyTitle="لا شهادات بعد"
        emptyDescription="تصدر الشهادة تلقائياً حين يُكمل طالبٌ كورساً أو ينجح في اختباره."
        onRetry={() => load(1)}
      />

      {page < lastPage && (
        <div className="flex justify-center">
          <Button
            variant="ghost"
            loading={loading}
            loadingLabel="جارٍ التحميل…"
            onClick={() => load(page + 1)}
          >
            عرض المزيد
          </Button>
        </div>
      )}
    </div>
  );
}
