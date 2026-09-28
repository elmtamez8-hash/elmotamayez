"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type ComponentType, type ReactNode } from "react";

import { AssistantScopeForm } from "@/components/community/AssistantScopeForm";
import {
  BookIcon,
  ChevronDownIcon,
  HistoryIcon,
  LockIcon,
  MembersIcon,
  ShieldIcon,
  UserPlusIcon,
  UsersIcon,
  type IconProps,
} from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { assistants, scopeSummary, type AssistantAssignment } from "@/lib/assistants";
import { userMessage } from "@/lib/errors";
import { counted, NOUNS } from "@/lib/labels";
import type { Course } from "@/lib/types";

/**
 * The teacher's team (spec 010 · US1).
 *
 * ⚠️ THE ONLY WAY IN IS THE INVITATION ON THE MEMBERS SCREEN, AND THE HEADER'S
 * «دعوة مساعد» IS A LINK TO IT — NOT A FORM. Membership is written by the
 * invitation flow, which is shipped with its own screen; the assignment rides
 * it. A second door would be a second membership story, and the two disagree the
 * first time somebody uses the older one. A signpost to the one door is not a
 * second door.
 *
 * ⚠️ AND THE PERMISSIONS ARE NOT EDITED HERE EITHER. What an assistant may DO is
 * a set of ticks on the roles screen this product already has (ق-١) — this page
 * says where it is rather than growing a second permission form, because two
 * screens that both claim to answer «what may this person do» is how one of them
 * starts lying. So the row's summary is its COURSE SCOPE, the one thing this
 * screen owns; the payload carries no role and none is invented for it.
 */
export default function AssistantsPage() {
  const [rows, setRows] = useState<AssistantAssignment[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  // Held here, keyed by assignment, so the refetch after a save does not fold
  // the editor the teacher is still looking at.
  const [open, setOpen] = useState<ReadonlySet<string>>(() => new Set());

  const load = useCallback(() => {
    setState("loading");

    Promise.all([assistants.list(), api.get<{ data: Course[] }>("/courses?per_page=200")])
      .then(([team, courseList]) => {
        setRows(team.data ?? []);
        setCourses(courseList.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  const save = (uuid: string, courseUuids: string[]) => {
    setBusy(uuid);
    setProblem(null);

    assistants
      .setScope(uuid, courseUuids)
      .then(load)
      // Never a raw error: 422 lands under its field, everything else becomes a
      // sentence.
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setBusy(null));
  };

  const revoke = (uuid: string) => {
    setBusy(uuid);
    setProblem(null);

    assistants
      .revoke(uuid)
      .then(load)
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setBusy(null));
  };

  const toggle = (uuid: string) =>
    setOpen((current) => {
      const next = new Set(current);

      if (next.has(uuid)) next.delete(uuid);
      else next.add(uuid);

      return next;
    });

  const active = rows.filter((row) => row.revoked_at === null);
  const past = rows.filter((row) => row.revoked_at !== null);
  const courseOptions = courses.map((course) => ({ uuid: course.uuid, title: course.title }));

  return (
    <div className="mx-auto max-w-3xl space-y-8">
      <div className="space-y-4">
        <PageHeader
          Icon={MembersIcon}
          title="فريق المساعدين"
          description="من يعمل معك، وعلى أيّ كورسات."
          actions={
            <Button href="/members" iconStart={<UserPlusIcon />}>
              دعوة مساعد
            </Button>
          }
        />
        <HouseRules />
      </div>

      {state === "loading" && <RowsSkeleton count={3} />}

      {state === "error" && <ErrorState onRetry={load} />}

      {state === "ready" && (
        <>
          {problem !== null && <Alert tone="danger" title="تعذّر الحفظ">{problem}</Alert>}

          {active.length === 0 ? (
            <EmptyState
              Icon={UsersIcon}
              title="لا مساعدين بعد"
              description="يُضاف المساعد بدعوته إلى فريقك من شاشة الفريق؛ ويظهر هنا فور قبوله الدعوة."
              action={
                <Button href="/members" variant="secondary" iconStart={<UserPlusIcon />}>
                  ادعُ أوّل مساعد
                </Button>
              }
            />
          ) : (
            <section aria-labelledby="active-assistants" className="space-y-4">
              <SectionHeading
                id="active-assistants"
                title="على رأس المهمة"
                Icon={UsersIcon}
                description={counted(active.length, NOUNS.assistants)}
              />
              <ul className="space-y-4">
                {active.map((row, index) => (
                  <li
                    key={row.uuid}
                    /* The existing arrival, not a new keyframe: `banner-rise` is
                       `both`-filled and the global reduced-motion block zeroes
                       its delay too. Capped, so a long team never arrives late. */
                    className="banner-rise"
                    style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
                  >
                    <AssistantCard
                      row={row}
                      courses={courseOptions}
                      busy={busy === row.uuid}
                      open={open.has(row.uuid)}
                      onToggle={() => toggle(row.uuid)}
                      onSave={(uuids) => save(row.uuid, uuids)}
                      onRevoke={() => revoke(row.uuid)}
                    />
                  </li>
                ))}
              </ul>
            </section>
          )}

          {past.length > 0 && (
            <Card as="section" padding="sm">
              {/* ⚠️ THE WITHDRAWN ARE LISTED RATHER THAN HIDDEN. «من صحّح هذه الورقة في
                  آذار» is a question a teacher asks about somebody who has left, and a
                  row that vanishes is a row nobody can ask about (FR-009). */}
              <div className="mb-3 px-2 pt-2">
                <SectionHeading id="past-assistants" title="مَن انتهت مهمّتهم" Icon={HistoryIcon} />
              </div>
              <ul aria-labelledby="past-assistants" className="divide-y divide-line">
                {past.map((row) => (
                  <li
                    key={row.uuid}
                    className="flex items-center gap-3 rounded-2xl px-2 py-2.5 text-sm text-ink transition-colors duration-150 hover:bg-primary-soft/40"
                  >
                    <Avatar url={null} name={row.assistant?.name ?? "—"} size="sm" />
                    <span className="min-w-0 flex-1 truncate text-ink-muted">
                      {row.assistant?.name ?? "—"}
                    </span>
                    <Badge tone="neutral">أُنهيت المهمة</Badge>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}
    </div>
  );
}

/**
 * The three things a teacher looks for on this screen and will not find here,
 * each with where it lives. One quiet panel, not three cards: they are notes
 * about the screen, not objects on it.
 */
function HouseRules() {
  return (
    <ul className="banner-rise divide-y divide-line rounded-3xl border border-line bg-surface-raised px-4 text-sm text-ink-muted">
      <Rule Icon={ShieldIcon}>
        <strong className="text-ink">ما</strong> يستطيع كلٌّ منهم فعله يُضبط بنداً بنداً على
        دوره من شاشة الأدوار في لوحة الإدارة، لا من هنا.
      </Rule>
      <Rule Icon={UserPlusIcon}>
        يُدعى المساعد ويُزال من{" "}
        <Link
          href="/members"
          className="rounded-sm font-medium text-primary-ink underline underline-offset-4 transition-colors hover:decoration-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          شاشة الأعضاء
        </Link>
        .
      </Rule>
      <Rule Icon={LockIcon}>لا يرى أيّ مساعدٍ بياناتٍ ماليّة مهما مُنح من بنود.</Rule>
    </ul>
  );
}

function Rule({ Icon, children }: { Icon: ComponentType<IconProps>; children: ReactNode }) {
  return (
    <li className="flex items-start gap-3 py-3">
      <span className="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary-ink">
        <Icon className="h-4 w-4" />
      </span>
      <span className="leading-relaxed">{children}</span>
    </li>
  );
}

function AssistantCard({
  row,
  courses,
  busy,
  open,
  onToggle,
  onSave,
  onRevoke,
}: {
  row: AssistantAssignment;
  courses: { uuid: string; title: string }[];
  busy: boolean;
  open: boolean;
  onToggle: () => void;
  onSave: (courseUuids: string[]) => void;
  onRevoke: () => void;
}) {
  const name = row.assistant?.name ?? "—";
  const regionId = `scope-editor-${row.uuid}`;

  return (
    <Card interactive>
      <div className="flex items-start gap-4">
        <Avatar url={null} name={name} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h4 className="font-bold text-ink">{name}</h4>
            <Badge tone="success">في الفريق</Badge>
          </div>

          <p className="mt-1.5 flex items-center gap-1.5 text-sm text-ink-muted">
            <BookIcon className="h-4 w-4 shrink-0 text-primary-ink" />
            {/* ⚠️ `is_confined`, never `courses.length`: an empty list is EVERY
                course, and reading it as none is the misreading the form's own
                notice exists to prevent. */}
            {row.is_confined
              ? `مقصور على ${counted(row.courses.length, { ...NOUNS.courses, two: "كورسين" })}`
              : scopeSummary(row)}
          </p>

          {row.is_confined && row.courses.length > 0 && (
            <ul className="mt-2.5 flex flex-wrap gap-1.5" aria-label="كورسات هذا المساعد">
              {row.courses.map((course) => (
                <li
                  key={course.uuid}
                  className="rounded-full border border-line bg-surface px-2.5 py-0.5 text-xs text-ink"
                >
                  {course.title}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-4">
        <Button
          variant="ghost"
          size="sm"
          onClick={onToggle}
          expanded={open}
          controls={regionId}
          iconEnd={
            <span
              className={`inline-flex transition-transform duration-200 ${open ? "rotate-180" : ""}`}
            >
              <ChevronDownIcon className="h-4 w-4" />
            </span>
          }
        >
          {open ? "إخفاء الكورسات" : "تعديل الكورسات"}
        </Button>

        {/* Two presses: ending the assignment cannot be taken back here — the
            assistant has to be invited to the team again. Calm until armed; the
            armed state is the danger fill. */}
        <ConfirmButton
          size="sm"
          variant="secondary"
          disabled={busy}
          confirmLabel="اضغط مجدداً لإنهاء المهمة"
          onConfirm={onRevoke}
        >
          إنهاء المهمة
        </ConfirmButton>
      </div>

      {/* Hidden, not unmounted: the region `aria-controls` names always exists,
          and ticks made before a fold are still there when it opens again. The
          arrival replays on every opening because `display` comes back. */}
      <div id={regionId} hidden={!open} className="banner-rise mt-4 rounded-2xl bg-surface p-4">
        <AssistantScopeForm assignment={row} courses={courses} onSave={onSave} busy={busy} />
      </div>
    </Card>
  );
}
