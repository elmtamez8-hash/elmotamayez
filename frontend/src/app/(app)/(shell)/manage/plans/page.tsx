"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, SelectField, TextField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { CreditsIcon, ListIcon, SparkIcon } from "@/components/icons";
import { Table, type Column } from "@/components/ui/Table";
import { api, ApiError, fieldErrors } from "@/lib/api";
import { manageCohorts } from "@/lib/cohorts";
import { errorCode, userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import {
  plans as plansApi,
  planShape,
  type PlanChangeRequest,
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
/**
 * ⚠️ 036 — `course_uuid` AND `cohort_uuid` ARE TWO FIELDS FOR ONE COLUMN, ON
 * PURPOSE. `plans.coverage_uuid` holds whichever public identifier the coverage
 * names; but a GROUP is picked by first picking its course, so the form has to
 * remember both — and collapsing them into one string is how the group picker
 * loses its course the moment the teacher changes their mind.
 */
type Draft = {
  title: string;
  shape: "duration" | "sessions";
  duration_days: string;
  session_count: string;
  session_type: SessionType;
  coverage_type: PlanCoverage;
  course_uuid: string;
  cohort_uuid: string;
  /** Only read when the plan being edited is already priced — see `save()`. */
  requested_price: string;
  reason: string;
};

const EMPTY: Draft = {
  title: "",
  shape: "duration",
  duration_days: "30",
  session_count: "",
  session_type: "individual",
  coverage_type: "workspace",
  course_uuid: "",
  cohort_uuid: "",
  requested_price: "",
  reason: "",
};

export default function ManagePlansPage() {
  const [rows, setRows] = useState<Plan[]>([]);
  const [courseOptions, setCourseOptions] = useState<Array<{ value: string; label: string }>>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [draft, setDraft] = useState<Draft>(EMPTY);
  const [editing, setEditing] = useState<Plan | null>(null);
  const [saving, setSaving] = useState(false);
  const [cohortOptions, setCohortOptions] = useState<Array<{ value: string; label: string }>>([]);
  const [requests, setRequests] = useState<PlanChangeRequest[]>([]);

  /*
   * ⛔ 036 · FR-013 — «المدرّسُ يُخبَرُ … قبلَ التنفيذِ لا بعدَه». The server
   * refuses the first request with the names of the groups that would go dark
   * and writes nothing; this holds what it said until the teacher answers.
   * Re-sending the SAME payload with `acknowledge_hidden_cohorts` is the answer,
   * so the payload is kept here rather than rebuilt — a second construction is a
   * second chance to send something the warning did not describe.
   */
  const [hiding, setHiding] = useState<{
    names: string[];
    payload: SavePlanPayload;
    uuid: string | null;
  } | null>(null);

  /*
   * ⚠️ 036 — EDITING A PRICED PLAN IS A REQUEST, NOT A SAVE, and this one boolean
   * is what the whole form reads. The server refuses to move the shape or the
   * coverage of a plan the platform has put a number on; the way through is to
   * ask. Deriving that from anything other than the row's own price would be a
   * second spelling of the server's rule.
   */
  const asking = editing !== null && editing.price_minor !== null;

  const load = useCallback(async () => {
    setState("loading");

    try {
      const [list, courses, asks] = await Promise.all([
        plansApi.manage.list(),
        // The same call `manage/announcements` makes: there is no `courses.list()`
        // in the lib, and inventing one here would be a second spelling of a
        // request three screens already send inline.
        api
          .get<{ data: Array<{ uuid: string; title: string }> }>("/courses?per_page=200")
          .catch(() => ({ data: [] })),
        /*
         * ⚠️ SWALLOWED ON PURPOSE, AND ONLY THIS ONE. The plans are the page; the
         * requests are a panel underneath it. A failure here must not take the
         * whole screen to «تعذّر تحميل البيانات» — that is the `/dashboard`
         * defect, where one permission-shaped read killed three good ones.
         */
        plansApi.manage.changeRequests().catch(() => ({ data: [] })),
      ]);

      setRequests(asks.data ?? []);
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

  /*
   * ⚠️ THE GROUPS OF THE CHOSEN COURSE, FETCHED WHEN IT IS CHOSEN. There is no
   * «all my groups» endpoint and inventing one for a picker would be a list read
   * to answer a question one course already knows — `manage/courses/{uuid}/cohorts`
   * is the same call the groups tab makes, so the two cannot disagree about what
   * a group is called.
   *
   * ⚠️ AND IT IS CLEARED WHEN THE COURSE CHANGES, or the picker keeps offering
   * the previous course's groups and the save is refused by the server for a
   * reason the screen never showed.
   */
  useEffect(() => {
    if (draft.coverage_type !== "cohort" || draft.course_uuid === "") {
      setCohortOptions([]);

      return;
    }

    let live = true;

    void manageCohorts
      .list(draft.course_uuid)
      .then((res) => {
        if (live) {
          setCohortOptions(
            (res.data ?? []).map((cohort) => ({ value: cohort.uuid, label: cohort.name })),
          );
        }
      })
      .catch(() => {
        if (live) setCohortOptions([]);
      });

    return () => {
      live = false;
    };
  }, [draft.coverage_type, draft.course_uuid]);

  /*
   * ⛔ THE FIELD THE SHAPE DOES NOT USE IS SENT AS `null`, NEVER LEFT OUT. The
   * form keeps the state of a field it stopped showing, so a teacher who typed 30
   * and then switched to «حصص» would otherwise reach the server with BOTH — and
   * be refused with a sentence about a field no longer on their screen.
   */
  function shapeOf(): { duration_days: number | null; session_count: number | null } {
    return draft.shape === "sessions"
      ? { duration_days: null, session_count: Number(draft.session_count) }
      : { duration_days: Number(draft.duration_days), session_count: null };
  }

  /**
   * Which public identifier this coverage names.
   *
   * Cleared rather than kept for workspace coverage: two columns that disagree
   * are a row whose meaning depends on which one the next reader trusts.
   */
  function coverageUuidOf(): string | null {
    if (draft.coverage_type === "cohort") return draft.cohort_uuid || null;
    if (draft.coverage_type === "course") return draft.course_uuid || null;

    return null;
  }

  /**
   * The names of the groups a refusal named, or `null` when it was some other
   * refusal.
   *
   * ⚠️ READ FROM THE `code`, NEVER FROM THE SENTENCE. Every other refusal on
   * this screen is final — no field the teacher can add makes a platform-priced
   * plan movable — so a match on Arabic prose would offer «نفِّذ رغم ذلك» under a
   * message that acknowledging cannot get past, and would break the first time
   * somebody improved the wording.
   */
  function hiddenCohortsIn(error: unknown): string[] | null {
    if (!(error instanceof ApiError) || errorCode(error.body) !== "plan_would_hide_cohorts") {
      return null;
    }

    const body = error.body;

    if (typeof body !== "object" || body === null || !("hidden_cohorts" in body)) return [];

    const names = (body as { hidden_cohorts: unknown }).hidden_cohorts;

    return Array.isArray(names) ? names.filter((name): name is string => typeof name === "string") : [];
  }

  /**
   * One write for the form and for the sell/stop switch, so both learn about a
   * group going dark in the same place.
   */
  async function writePlan(payload: SavePlanPayload, uuid: string | null) {
    if (uuid === null) await plansApi.manage.create(payload);
    else await plansApi.manage.update(uuid, payload);
  }

  /**
   * 036 · FR-013 — stop selling a plan, or start again.
   *
   * ⛔ IT DID NOT EXIST, AND THAT IS WHY THE WARNING HAD NOTHING TO WARN ABOUT.
   * `is_active` was on the payload type and on the badge and on no control at
   * all, so a teacher could not stop selling a plan from the product — and the
   * form, which never sent the field, silently switched a stopped plan back ON
   * at every edit, because the Action defaults an absent `is_active` to true.
   */
  function payloadFor(row: Plan, isActive: boolean): SavePlanPayload {
    return {
      title: row.title,
      duration_days: row.duration_days,
      session_count: row.session_count,
      session_type: row.session_type,
      coverage_type: row.coverage_type,
      coverage_uuid: row.coverage_uuid,
      is_active: isActive,
    };
  }

  async function submit(payload: SavePlanPayload, uuid: string | null) {
    setSaving(true);
    setErrors({});
    setProblem(null);

    try {
      await writePlan(payload, uuid);
      setHiding(null);
      setDraft(EMPTY);
      setEditing(null);
      await load();
    } catch (error) {
      const hidden = hiddenCohortsIn(error);

      if (hidden !== null) {
        setHiding({ names: hidden, payload, uuid });

        return;
      }

      const fields = fieldErrors(error);

      if (Object.keys(fields).length > 0) setErrors(fields);
      else setProblem(userMessage(error));
    } finally {
      setSaving(false);
    }
  }

  async function save() {
    setSaving(true);
    setErrors({});
    setProblem(null);

    try {
      if (asking && editing !== null) {
        await plansApi.manage.requestChange(editing.uuid, {
          ...shapeOf(),
          session_type: draft.session_type,
          coverage_type: draft.coverage_type,
          coverage_uuid: coverageUuidOf(),
          // ⚠️ EMPTY IS «THE PLATFORM DECIDES», never zero. The teacher may ask
          // for a shape and leave the number to whoever sets numbers.
          requested_price_minor: draft.requested_price === "" ? null : Number(draft.requested_price),
          reason: draft.reason || null,
        });
      } else {
        const payload: SavePlanPayload = {
          title: draft.title,
          ...shapeOf(),
          session_type: draft.session_type,
          coverage_type: draft.coverage_type,
          coverage_uuid: coverageUuidOf(),
          /*
           * ⚠️ CARRIED, NOT DEFAULTED. The Action reads an absent `is_active` as
           * `true`, so a form that left the field out turned a stopped plan back
           * on at every edit — a plan back on sale from a save about its title.
           */
          is_active: editing?.is_active ?? true,
        };

        try {
          await writePlan(payload, editing === null ? null : editing.uuid);
        } catch (error) {
          const hidden = hiddenCohortsIn(error);

          if (hidden === null) throw error;

          setHiding({ names: hidden, payload, uuid: editing === null ? null : editing.uuid });
          setSaving(false);

          return;
        }
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
    {
      key: "duration",
      header: "المدّة",
      // خانةٌ تحتَ عنوانِ «المدّة» تحتاجُ علامةً مرئيّة: الفراغُ يُقرأُ عموداً لم
      // يُحمَّل، بينما «—» تقولُ «لا مدّةَ لهذه الباقة».
      render: (row) => planShape(row) ?? "—",
    },
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
      key: "selling",
      header: "",
      /*
       * ⛔ 036 · FR-013 — THE SWITCH THE WARNING GUARDS, AND IT WAS MISSING.
       * `is_active` had a badge and a payload field and no control anywhere in
       * `frontend/src`, so «أوقِف باقة عن البيع» was a thing the API could do and
       * the product could not — the endpoint-with-no-caller family. The warning
       * has nothing to warn about until this button exists.
       */
      render: (row) => (
        <Button
          type="button"
          variant="ghost"
          disabled={saving}
          onClick={() => void submit(payloadFor(row, !row.is_active), row.uuid)}
        >
          {row.is_active ? "أوقِف عن البيع" : "أعِدْ للبيع"}
        </Button>
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
            setEditing(row);
            setErrors({});
            setProblem(null);
            /*
             * ⛔ `String(row.duration_days)` PUT THE TEXT «null» IN A NUMBER
             * FIELD, and that is how a 12-session plan became a 30-day one. The
             * browser sanitises the invalid value, the required field renders
             * EMPTY, the teacher fills it in, and the count is gone — saved, with
             * no error anywhere, at the price the platform set for twelve
             * lessons.
             */
            const bySessions = row.session_count !== null;

            setDraft({
              ...EMPTY,
              title: row.title,
              shape: bySessions ? "sessions" : "duration",
              duration_days: row.duration_days === null ? "" : String(row.duration_days),
              session_count: row.session_count === null ? "" : String(row.session_count),
              session_type: row.session_type,
              coverage_type: row.coverage_type,
              course_uuid: row.coverage_type === "course" ? (row.coverage_uuid ?? "") : "",
              cohort_uuid: row.coverage_type === "cohort" ? (row.coverage_uuid ?? "") : "",
            });
          }}
        >
          {row.price_minor === null ? "تعديل" : "اطلب تعديلاً"}
        </Button>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={CreditsIcon}
        title="باقات الاشتراك"
        description="اشتراك بالشهر يفتح لطلابك ما تغطّيه الباقة طوال مدّتها، وحصصه لا تخصم من رصيدهم."
      />

      <Alert tone="info" title="ثلاث صيغ للبيع، وهذه واحدة منها">
        الحصّة المفردة وباقة عدد الحصص تُسعَّران تلقائياً من سعرك المعتمَد، وتظهران لطلابك في
        صفحة الأرصدة. هذه الصفحة للاشتراك بالمدّة وحدَه — تكتب أنت المدّة والتغطية، وتحدّد
        المنصّة السعر لأنّ الاشتراك وصولٌ إلى تدريس.
      </Alert>

      {problem && <Alert tone="danger" title="تعذّر الحفظ">{problem}</Alert>}

      {/*
        ⛔ 036 · FR-013 — THE WARNING, AND NOTHING HAS BEEN WRITTEN YET. The
        server ran the save, read the price gate on both sides of it and rolled
        it back; these are the groups that would drop out of every picker, and
        the students already in them would stop seeing their own group offered.
        A «تراجع» that merely closed a box would be a warning after the fact.
      */}
      {hiding !== null && (
        <Alert tone="warning" title="هذا التعديل يُخرِج مجموعة فيها طلاب من العرض">
          <div className="space-y-3">
            <p>
              {/* ⚠️ THE NAMES, NOT ONLY THE NUMBER. The remedy is to price or
                  re-enable one particular plan, and a count alone leaves the
                  teacher guessing which. */}
              ستخرج من العرض: {hiding.names.join("، ")}. الطلاب الموجودون فيها يبقون مكانهم،
              ولن تظهر المجموعة لمن يبحث عن مكان.
            </p>

            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="danger"
                loading={saving}
                loadingLabel="جارٍ التنفيذ…"
                onClick={() => void submit({ ...hiding.payload, acknowledge_hidden_cohorts: true }, hiding.uuid)}
              >
                أعرف، نفِّذ
              </Button>

              <Button type="button" variant="ghost" onClick={() => setHiding(null)}>
                تراجع
              </Button>
            </div>
          </div>
        </Alert>
      )}

      <Card as="section">
        <div className="space-y-4">
          <SectionHeading
            id="plan-form"
            Icon={SparkIcon}
            title={editing === null ? "باقة جديدة" : asking ? "طلب تعديل" : "تعديل الباقة"}
          />

          {/*
            ⚠️ الاسمُ يبقى للمدرّسِ حتى على باقةٍ مسعَّرة — لا يُحرِّكُ ما سُعِّر —
            فهو يُحفَظُ مباشرةً ولا يدخلُ الطلب، ولذلك يغيبُ من نموذجِ الطلب.
          */}
          {!asking && (
            <TextField
              id="plan-title"
              label="اسم الباقة"
              value={draft.title}
              onChange={(title) => setDraft({ ...draft, title })}
              error={errors.title}
              required
            />
          )}

          {/*
            ⛔ **شكلٌ واحدٌ لا شكلان.** الخادمُ يرفضُ صفّاً يحملُ الاثنَينِ ويرفضُ
            صفّاً بلا أيِّهما، فنموذجٌ يعرضُ الحقلَينِ معاً هو نموذجٌ رسالتُه
            الوحيدةُ تصلُ بعدَ الحفظ. والمنتقي هو القرارُ نفسُه.
          */}
          <SelectField
            id="plan-shape"
            label="ما الذي تبيعه الباقة"
            hint="باقة الحصص تضيف رصيد حصص لطالبك ولا تكتب اشتراكاً، ولا بدّ أن تخصّ كورساً أو مجموعة."
            value={draft.shape}
            onChange={(value) =>
              setDraft({ ...draft, shape: value === "sessions" ? "sessions" : "duration" })
            }
            options={[
              { value: "duration", label: "مدّة بالأيّام" },
              { value: "sessions", label: "عدد من الحصص" },
            ]}
            required
          />

          {draft.shape === "duration" ? (
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
          ) : (
            <NumberField
              id="plan-session-count"
              label="عدد الحصص"
              hint="يُضاف هذا العدد إلى رصيد الطالب في هذا الكورس، ويُخصم منه مع كلّ حضور."
              value={draft.session_count}
              onChange={(session_count) => setDraft({ ...draft, session_count })}
              min={1}
              max={200}
              error={errors.session_count}
              required
            />
          )}

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
              { value: "cohort", label: "مجموعة واحدة" },
            ]}
            error={errors.coverage_type}
            required
          />

          {/*
            ⚠️ الكورسُ يُسأَلُ في الحالتَين — وفي حالةِ المجموعةِ هو الخطوةُ
            الأولى لا التغطية: المجموعةُ تُنتقى من كورسِها، والعمودُ يحملُ
            معرّفَ المجموعةِ وحدَه.
          */}
          {draft.coverage_type !== "workspace" && (
            <SelectField
              id="plan-course"
              label={draft.coverage_type === "cohort" ? "الكورس الذي فيه المجموعة" : "الكورس"}
              value={draft.course_uuid}
              onChange={(course_uuid) => setDraft({ ...draft, course_uuid, cohort_uuid: "" })}
              options={courseOptions}
              placeholder="اختر كورساً"
              error={draft.coverage_type === "course" ? errors.coverage_uuid : undefined}
              required
            />
          )}

          {draft.coverage_type === "cohort" && (
            <SelectField
              id="plan-cohort"
              label="المجموعة"
              hint="سعر هذه المجموعة يحلّ محلّ سعر الكورس لمن يقف عليها، ولا يُضاف إليه."
              value={draft.cohort_uuid}
              onChange={(cohort_uuid) => setDraft({ ...draft, cohort_uuid })}
              options={cohortOptions}
              placeholder={draft.course_uuid === "" ? "اختر الكورس أوّلاً" : "اختر مجموعة"}
              error={errors.coverage_uuid}
              required
            />
          )}

          {/*
            ⛔ **حقلانِ لا يظهرانِ إلّا حينَ يكونُ هذا طلباً.** الباقةُ المسعَّرةُ
            لا يُحرِّكُ المدرّسُ شكلَها ولا تغطيتَها — يطلبُ، والمنصّةُ تقرّر.
            وتركُ الثمنِ فارغاً يعني «حدّدوه أنتم» كما هي القاعدةُ أصلاً.
          */}
          {asking && (
            <>
              <NumberField
                id="plan-requested-price"
                label="السعر المقترح بالوحدة الصغرى (اختياريّ)"
                hint="٣٠٠٫٠٠ ريالاً تُكتب 30000. اتركه فارغاً لتحدّده المنصّة."
                value={draft.requested_price}
                onChange={(requested_price) => setDraft({ ...draft, requested_price })}
                min={1}
                error={errors.requested_price_minor}
              />

              <TextField
                id="plan-reason"
                label="لماذا تطلب التعديل"
                value={draft.reason}
                onChange={(reason) => setDraft({ ...draft, reason })}
                error={errors.reason}
              />
            </>
          )}

          <div className="flex items-center gap-3">
            <Button type="button" onClick={() => void save()} disabled={saving}>
              {editing === null ? "أضِف الباقة" : asking ? "أرسِل الطلب" : "احفظ التعديل"}
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

      {/*
        ⛔ **طلباتُه هو، معروضةً بقرارِها.** المدرّسُ الذي لا يرى إلّا المعلَّقَ
        لا سبيلَ له ليعرفَ ما حلَّ بطلبِه السابقِ من الشاشةِ التي طلبَ منها —
        يصلُه الجوابُ في إشعارٍ ولا مكانَ آخر، والإشعارُ شيءٌ يفوت. ولذلك
        المحسومةُ هنا أيضاً، وسببُ الرفضِ معها.
      */}
      {requests.length > 0 && (
        <Card as="section">
          <div className="space-y-3">
            <SectionHeading id="plan-requests" Icon={ListIcon} title="طلبات التعديل" />

            <ul className="space-y-3">
              {requests.map((ask) => (
                <li key={ask.uuid} className="border-b border-line pb-3 last:border-0 last:pb-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-ink">{ask.plan_title}</span>
                    <Badge
                      tone={
                        ask.status === "approved"
                          ? "success"
                          : ask.status === "rejected"
                            ? "danger"
                            : "warning"
                      }
                    >
                      {ask.status_label}
                    </Badge>
                  </div>

                  {/*
                    الجملتانِ من الخادم: اشتقاقُهما هنا تهجئةٌ ثانيةٌ لِـ`planShape`.
                  */}
                  <p className="mt-1 text-sm text-ink-muted">
                    من {ask.current_shape ?? "—"} · {ask.current_coverage_label} إلى{" "}
                    {ask.requested_shape ?? "—"} · {ask.requested_coverage_label}
                    {ask.requested_price_minor !== null && (
                      <> · بسعر مقترح {formatMinorMoney(ask.requested_price_minor, ask.currency)}</>
                    )}
                  </p>

                  {ask.decision_reason !== null && (
                    <p className="mt-1 text-sm text-ink-muted">ردّ الإدارة: {ask.decision_reason}</p>
                  )}
                </li>
              ))}
            </ul>
          </div>
        </Card>
      )}
    </div>
  );
}
