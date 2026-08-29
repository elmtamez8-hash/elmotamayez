"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, SelectField, TextField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import {
  plans as plansApi,
  planDuration,
  SESSION_TYPE_LABELS,
  type Plan,
  type PlanCoverage,
  type SavePlanPayload,
  type SessionType,
} from "@/lib/plans";

/**
 * The teacher's subscription plans (spec 011 · US4 · FR-025).
 *
 * ⚠️ THE PRICE FIELD IS NOT ON THIS FORM, AND THE SCREEN SAYS WHY. A subscription
 * is access to teaching, so the platform sets its price (Q4) — and a screen that
 * simply omitted the field would leave a teacher creating plans, seeing them
 * listed, and never learning that nobody can buy them. The «تنتظر التسعير» badge
 * is the whole difference between a feature and a support ticket.
 *
 * ⚠️ AND THIS IS ONE OF THREE PRICING SHAPES THE TEACHER OFFERS. «بالحصّة» and
 * «بعدد من الحصص» are credit packages, priced automatically from this teacher's
 * approved rate; this table is the third — time. The note at the top says so,
 * because a page called «الباقات» that shows neither of the other two reads as a
 * screen that has lost the teacher's session packages.
 */
type Draft = {
  title: string;
  duration_days: string;
  session_type: SessionType;
  coverage_type: PlanCoverage;
  coverage_uuid: string;
};

const EMPTY: Draft = {
  title: "",
  duration_days: "30",
  session_type: "individual",
  coverage_type: "workspace",
  coverage_uuid: "",
};

export default function ManagePlansPage() {
  const [rows, setRows] = useState<Plan[]>([]);
  const [courseOptions, setCourseOptions] = useState<Array<{ value: string; label: string }>>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [draft, setDraft] = useState<Draft>(EMPTY);
  const [editing, setEditing] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setState("loading");

    try {
      const [list, courses] = await Promise.all([
        plansApi.manage.list(),
        // The same call `manage/announcements` makes: there is no `courses.list()`
        // in the lib, and inventing one here would be a second spelling of a
        // request three screens already send inline.
        api
          .get<{ data: Array<{ uuid: string; title: string }> }>("/courses")
          .catch(() => ({ data: [] })),
      ]);

      setRows(list.data);
      setCourseOptions(
        (courses.data ?? []).map((course) => ({ value: course.uuid, label: course.title })),
      );
      setState("ready");
    } catch (error) {
      setProblem(userMessage(error));
      setState("error");
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function save() {
    setSaving(true);
    setErrors({});
    setProblem(null);

    const payload: SavePlanPayload = {
      title: draft.title,
      duration_days: Number(draft.duration_days),
      session_type: draft.session_type,
      coverage_type: draft.coverage_type,
      // Cleared rather than kept when the plan covers the whole workspace: two
      // columns that disagree are a row whose meaning depends on which one the
      // next reader trusts.
      coverage_uuid: draft.coverage_type === "course" ? draft.coverage_uuid : null,
    };

    try {
      if (editing === null) {
        await plansApi.manage.create(payload);
      } else {
        await plansApi.manage.update(editing, payload);
      }

      setDraft(EMPTY);
      setEditing(null);
      await load();
    } catch (error) {
      // 422 lands under its field; everything else becomes one Arabic sentence.
      const fields = fieldErrors(error);

      if (Object.keys(fields).length > 0) setErrors(fields);
      else setProblem(userMessage(error));
    } finally {
      setSaving(false);
    }
  }

  const columns: Column<Plan>[] = [
    { key: "title", header: "الباقة", render: (row) => row.title },
    { key: "duration", header: "المدّة", render: (row) => planDuration(row.duration_days) },
    {
      key: "type",
      header: "نوع الحصص",
      render: (row) => SESSION_TYPE_LABELS[row.session_type],
    },
    { key: "coverage", header: "التغطية", render: (row) => row.coverage_label },
    {
      key: "price",
      header: "السعر",
      numeric: true,
      render: (row) =>
        row.price_minor === null ? (
          <Badge tone="warning">تنتظر تسعير المنصّة</Badge>
        ) : (
          formatMinorMoney(row.price_minor, row.currency)
        ),
    },
    {
      key: "state",
      header: "الحالة",
      render: (row) =>
        row.is_sellable ? (
          <Badge tone="success">معروضة للبيع</Badge>
        ) : (
          <Badge tone="neutral">غير معروضة</Badge>
        ),
    },
    {
      key: "edit",
      header: "",
      render: (row) => (
        <Button
          type="button"
          variant="ghost"
          onClick={() => {
            setEditing(row.uuid);
            setDraft({
              title: row.title,
              duration_days: String(row.duration_days),
              session_type: row.session_type,
              coverage_type: row.coverage_type,
              coverage_uuid: row.coverage_uuid ?? "",
            });
          }}
        >
          تعديل
        </Button>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">باقات الاشتراك</h1>
        <p className="mt-1 text-sm text-ink-muted">
          اشتراك بالشهر يفتح لطلابك ما تغطّيه الباقة طوال مدّتها، وحصصه لا تخصم من رصيدهم.
        </p>
      </header>

      <Alert tone="info" title="ثلاث صيغ للبيع، وهذه واحدة منها">
        الحصّة المفردة وباقة عدد الحصص تُسعَّران تلقائياً من سعرك المعتمَد، وتظهران لطلابك في
        صفحة الأرصدة. هذه الصفحة للاشتراك بالمدّة وحدَه — تكتب أنت المدّة والتغطية، وتحدّد
        المنصّة السعر لأنّ الاشتراك وصولٌ إلى تدريس.
      </Alert>

      {problem && <Alert tone="danger" title="تعذّر الحفظ">{problem}</Alert>}

      <Card>
        <div className="space-y-4">
          <h2 className="text-base font-semibold text-ink">
            {editing === null ? "باقة جديدة" : "تعديل الباقة"}
          </h2>

          <TextField
            id="plan-title"
            label="اسم الباقة"
            value={draft.title}
            onChange={(title) => setDraft({ ...draft, title })}
            error={errors.title}
            required
          />

          <NumberField
            id="plan-duration"
            label="المدّة بالأيّام"
            hint="٣٠ يوماً تعني شهراً. اليوم وحدة لا لبس فيها، بخلاف «شهر»."
            value={draft.duration_days}
            onChange={(duration_days) => setDraft({ ...draft, duration_days })}
            min={1}
            max={730}
            error={errors.duration_days}
            required
          />

          <SelectField
            id="plan-session-type"
            label="نوع الحصص التي تغطّيها"
            hint="سعرك يختلف بحجم المجموعة، فالباقة تغطّي نوع الحصص المذكور هنا وحدَه."
            value={draft.session_type}
            onChange={(value) => setDraft({ ...draft, session_type: value as SessionType })}
            options={[
              { value: "individual", label: SESSION_TYPE_LABELS.individual },
              { value: "group", label: SESSION_TYPE_LABELS.group },
            ]}
            error={errors.session_type}
            required
          />

          <SelectField
            id="plan-coverage"
            label="ما الذي تغطّيه"
            value={draft.coverage_type}
            onChange={(value) => setDraft({ ...draft, coverage_type: value as PlanCoverage })}
            options={[
              { value: "workspace", label: "كلّ كورساتي" },
              { value: "course", label: "كورس واحد" },
            ]}
            error={errors.coverage_type}
            required
          />

          {draft.coverage_type === "course" && (
            <SelectField
              id="plan-course"
              label="الكورس"
              value={draft.coverage_uuid}
              onChange={(coverage_uuid) => setDraft({ ...draft, coverage_uuid })}
              options={courseOptions}
              placeholder="اختر كورساً"
              error={errors.coverage_uuid}
              required
            />
          )}

          <div className="flex items-center gap-3">
            <Button type="button" onClick={() => void save()} disabled={saving}>
              {editing === null ? "أضِف الباقة" : "احفظ التعديل"}
            </Button>

            {editing !== null && (
              <Button
                type="button"
                variant="ghost"
                onClick={() => {
                  setEditing(null);
                  setDraft(EMPTY);
                  setErrors({});
                }}
              >
                إلغاء
              </Button>
            )}
          </div>
        </div>
      </Card>

      <Table
        caption="باقات الاشتراك التي أعرضها"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.uuid}
        state={state === "ready" ? (rows.length === 0 ? "empty" : "ready") : state}
        emptyTitle="لا باقات بعد"
        emptyDescription="أضِف باقةً بالمدّة أعلاه، ثمّ تحدّد المنصّة سعرها قبل عرضها للطلاب."
        onRetry={() => void load()}
      />
    </div>
  );
}
