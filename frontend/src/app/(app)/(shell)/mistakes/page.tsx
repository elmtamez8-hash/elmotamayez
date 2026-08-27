"use client";

import { useCallback, useEffect, useState } from "react";

import {
  CheckIcon,
  CloseIcon,
  InfoIcon,
  MistakesIcon,
  PracticeIcon,
  ScheduleIcon,
  TagIcon,
  UsersIcon,
} from "@/components/icons";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import {
  mistakes,
  type Mistake,
  type MistakeFilterOptions,
  type MistakeFilters,
} from "@/lib/mistakes";
import { MistakeFilterBar } from "@/components/mistakes/MistakeFilterBar";

/**
 * Everything this student got wrong with this teacher, and the right answer.
 *
 * ⚠️ THE CORRECTION IS SHOWN, and that is the difference from the exam screen.
 * This is read after the paper is marked; a list of failures with nothing beside
 * them is a scoreboard, not a way to study.
 *
 * ⚠️ AND "FIXED" IS HIDDEN BY DEFAULT, NOT DELETED. A question they finally got
 * right is worth re-reading, so it stays one toggle away instead of vanishing.
 *
 * ⚠️ THE TWO ANSWERS ARE TOLD APART BY SHAPE AND BY A GLYPH, not by colour. The
 * whole page is a wrong answer sitting beside a right one, and a reader who
 * cannot separate the two tints would be looking at two identical blocks with
 * nothing saying which is which — so each carries its own label AND its own
 * mark. Same rule `SeatBadge` follows for a full session.
 *
 * ⚠️ AND IT USED TO SHOW EVERY REAL STUDENT AN ERROR. The endpoint answered for
 * «the workspace you are currently in» and refused with a `422` when there was
 * none — which is every student, being a member of no workspace. It spans the
 * teachers they study with now, each row names its own, and the bar narrows to
 * one.
 *
 * ⚠️ THE BAR'S OPTIONS ARE FETCHED, NEVER DERIVED HERE. The server builds them
 * from the notebook's own query, so an offered option always answers a row —
 * the rule spec 009's leaderboard picker was fixed under.
 */
export default function MistakesPage() {
  const [rows, setRows] = useState<Mistake[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [includeResolved, setIncludeResolved] = useState(false);
  const [filters, setFilters] = useState<MistakeFilters>({});
  const [options, setOptions] = useState<MistakeFilterOptions | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    mistakes
      .list({ ...filters, include_resolved: includeResolved })
      .then((response) => {
        setRows(response.data ?? []);
        setTotal(response.meta?.total ?? (response.data?.length ?? 0));
      })
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, [filters, includeResolved]);

  useEffect(load, [load]);

  /*
    ⚠️ NOT RE-FETCHED AS THE READER NARROWS, BUT RE-FETCHED WHEN THE VIEW SWITCHES.
    The two are different things. A narrowing must not recompute the options —
    each choice would then remove the others, so picking a teacher would empty
    the subject list and leave no way back without a reload. But «القائم» ⇄ «الكل»
    changes WHICH LIST IS ON SCREEN: derived from the standing set alone the bar
    vanished for anybody who had fixed everything, and «الكل» then listed their
    whole notebook with nothing to filter it by.
  */
  useEffect(() => {
    mistakes
      .filters(includeResolved)
      .then(setOptions)
      // Its own failure is not the page's: the list below still loads, and a bar
      // that cannot be built is simply not drawn.
      .catch(() => setOptions(null));
  }, [includeResolved]);

  /*
    ⚠️ «IS THERE ANYTHING TO PRACTISE» IS THE SERVER'S ANSWER, NOT A COUNT OF THE
    ROWS ON SCREEN. Counting unresolved rows here counts the CURRENT page of the
    CURRENT filter: with «الكل» pressed and a teacher chosen, a page of fixed
    questions would hide the button while other teachers still hold standing
    mistakes. And it cannot be read off the facets either — those follow the view
    now, so under «الكل» they are populated for a student who has fixed
    everything. `has_standing` is asked of the standing set whatever the view.
  */
  const hasStanding = options?.has_standing === true;
  const narrowed = Object.values(filters).some((entry) => entry !== undefined && entry !== "");

  const practiceParams = new URLSearchParams();

  for (const [key, entry] of Object.entries(filters)) {
    if (entry !== undefined && entry !== "") practiceParams.set(key, String(entry));
  }

  const practiceQuery = practiceParams.toString() === "" ? "" : `?${practiceParams}`;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-ink">
            {/* `className` REPLACES the icon's default size, so it is repeated. */}
            <MistakesIcon className="h-6 w-6 text-primary-ink" />
            دفتر أخطائي
          </h1>
          <p className="text-sm text-ink-muted">
            كلّ ما أخطأت فيه، ومعه الصواب وشرحه.
          </p>
        </div>

        {hasStanding && (
          /*
            A link, not a handler: the paper is built by the page it opens, on
            mount. Building here would mean an attempt written for somebody who
            closed the tab before the navigation landed.

            ⚠️ AND IT CARRIES THE FILTER, WHICH IS NOT A CONVENIENCE. A revision
            paper belongs to ONE teacher — one bank, one withholding rule — so
            with several to choose from and none named the server refuses. The
            reader looking at a narrowed notebook has already named one; passing
            it on is what turns «اختبرني في أخطائي» from an error page into the
            paper they were expecting. And a paper narrowed to the subject they
            are reading is the paper they asked for.
          */
          <Button
            href={`/mistakes/practice${practiceQuery}`}
            iconStart={<PracticeIcon className="h-4 w-4" />}
          >
            اختبرني في أخطائي
          </Button>
        )}
      </header>

      {/*
        ⚠️ TWO BUTTONS, NOT ONE THAT RENAMES ITSELF. The old control read
        «أظهر ما أصلحته» and turned into «القائم فقط» once pressed — so its label
        described the OTHER state, and the reader had no way to see which of the
        two they were currently looking at. A segmented pair states the choice and
        marks the answer, which is also what `aria-pressed` needs to be true of.
      */}
      <MistakeFilterBar options={options} value={filters} onChange={setFilters} />

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex gap-1 rounded-full border border-line bg-surface-raised p-1">
          {[
            { on: false, label: "القائم" },
            { on: true, label: "الكل" },
          ].map((option) => (
            <Button
              key={option.label}
              size="sm"
              variant={includeResolved === option.on ? "primary" : "ghost"}
              onClick={() => setIncludeResolved(option.on)}
            >
              {option.label}
            </Button>
          ))}
        </div>

        {!loading && error === null && rows.length > 0 && (
          <p className="text-sm text-ink-muted">
            <bdi>{total}</bdi> {includeResolved ? "سؤالاً في دفترك" : "سؤالاً ما زال قائماً"}
          </p>
        )}
      </div>

      {loading ? (
        <RowsSkeleton count={4} />
      ) : error !== null ? (
        <ErrorState description={error} onRetry={load} />
      ) : rows.length === 0 ? (
        /*
          ⚠️ THREE SENTENCES, BECAUSE «لا أخطاء» IS FALSE UNDER A FILTER. Telling a
          student they have made no mistakes when they have narrowed to one
          teacher is a page contradicting the list they just saw, and it hides
          the one action that helps: widening.
        */
        <EmptyState
          title={narrowed ? "لا نتائج بهذه التصفية" : includeResolved ? "لا أخطاء بعد" : "لا أخطاء قائمة"}
          description={
            narrowed
              ? "وسّع التصفية أو امسحها لترى بقيّة دفترك."
              : includeResolved
                ? "لم تخطئ في شيء بعد — يظهر هنا كلّ سؤال أخطأت فيه، ومعه الصواب."
                : "أصلحت كلّ ما أخطأت فيه. اضغط «الكل» لمراجعتها."
          }
        />
      ) : (
        <div className="space-y-4">
          {rows.map((row, index) => (
            /*
              ⚠️ KEYED ON THE FILTER TOO, so switching between «القائم» and «الكل»
              replays the arrival instead of swapping text inside cards that stay
              put — the change is what the reader pressed for, and a list that
              re-renders silently reads as a page that ignored them.
            */
            <div
              key={`${includeResolved}-${row.uuid}`}
              className="banner-rise"
              style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
            >
              <article className="rounded-3xl border border-line bg-surface-raised p-5 transition-colors duration-200 hover:border-primary/40">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <p className="text-base font-medium text-ink">{row.question?.content ?? "—"}</p>
                  <div className="flex shrink-0 gap-2">
                    {row.is_resolved ? (
                      <Badge tone="success">أصلحته</Badge>
                    ) : (
                      <Badge tone="warning">قائم</Badge>
                    )}
                    {row.times_wrong > 1 && (
                      // ⚠️ العربيّةُ لها مثنّى: «٢ مرّات» ليست جملةً عربيّة، وهي
                      // الحالةُ الأكثرُ شيوعاً هنا بفارقٍ كبير — سؤالٌ أُخطئ فيه
                      // مرّتَين أقربُ بكثيرٍ من سؤالٍ أُخطئ فيه سبعاً.
                      <Badge tone="neutral">
                        {row.times_wrong === 2
                          ? "أخطأت فيه مرّتين"
                          : `أخطأت فيه ${row.times_wrong} مرّات`}
                      </Badge>
                    )}
                  </div>
                </div>

                <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                  <div className="rounded-2xl border border-danger/25 bg-danger/5 p-3">
                    <dt className="mb-1 flex items-center gap-1.5 text-xs font-medium text-danger-ink">
                      <CloseIcon className="h-4 w-4 shrink-0" />
                      إجابتك
                    </dt>
                    <dd className="text-sm text-ink">
                      {/* An unanswered question is the strongest evidence of a gap
                          on the page, so it says so rather than showing a blank. */}
                      {Array.isArray(row.your_answer)
                        ? row.your_answer.length === 0
                          ? "تركته بلا إجابة"
                          : row.your_answer.join(" · ")
                        : row.your_answer}
                    </dd>
                  </div>
                  <div className="rounded-2xl border border-secondary/25 bg-secondary/5 p-3">
                    <dt className="mb-1 flex items-center gap-1.5 text-xs font-medium text-secondary-ink">
                      <CheckIcon className="h-4 w-4 shrink-0" />
                      الصواب
                    </dt>
                    <dd className="text-sm text-ink">
                      {row.correct_answer.length === 0 ? "—" : row.correct_answer.join(" · ")}
                    </dd>
                  </div>
                </dl>

                {row.question?.explanation != null && row.question.explanation !== "" && (
                  <p className="mt-3 flex items-start gap-2 rounded-2xl bg-primary-soft/50 p-3 text-sm text-ink">
                    <InfoIcon className="mt-0.5 h-4 w-4 shrink-0 text-primary-ink" />
                    <span>{row.question.explanation}</span>
                  </p>
                )}

                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted">
                  {/*
                    ⚠️ WHOSE QUESTION THIS IS, AND IT IS NOT DECORATION. The
                    notebook spans every teacher the reader studies with — it had
                    to, or it stayed an error page for all of them — and the rule
                    it must not break is that no question be read inside another
                    teacher's context. A list that spans teachers and says whose
                    each row is keeps that; one that spans them silently does not.
                  */}
                  {row.teacher !== null && (
                    <span className="flex items-center gap-1.5">
                      <UsersIcon className="h-4 w-4 shrink-0" />
                      {row.teacher.name}
                    </span>
                  )}
                  <span className="flex items-center gap-1.5">
                    <TagIcon className="h-4 w-4 shrink-0" />
                    {row.question?.concept?.name ?? "بلا فكرة"}
                  </span>
                  {row.question?.lesson != null && (
                    // The lesson is on the payload and was never rendered — which
                    // is the one field that tells a student WHERE to go and read
                    // the thing again.
                    <span className="flex items-center gap-1.5">
                      <MistakesIcon className="h-4 w-4 shrink-0" />
                      {row.question.lesson.title}
                    </span>
                  )}
                  <span className="flex items-center gap-1.5">
                    <ScheduleIcon className="h-4 w-4 shrink-0" />
                    {formatDate(row.answered_at)}
                  </span>
                </div>
              </article>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
