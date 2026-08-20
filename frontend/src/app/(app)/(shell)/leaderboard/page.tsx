"use client";

import { useCallback, useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import {
  gamification,
  type Leaderboard,
  type LeaderboardScopeOption,
} from "@/lib/gamification";

/**
 * Where the student stands, among people at their own level (FR-023).
 *
 * ⚠️ THE SLICE IS THE SERVER'S, and this screen does not widen it. The board
 * comes back already limited to the reader's level band and to fifty rows,
 * because competition only motivates while winning looks possible — a page that
 * asked for "the top 200" would undo the whole point of the band.
 *
 * ⚠️ AND AN EMPTY BOARD SAYS SOMETHING. Every student's first week starts empty,
 * and a bare table with no rows reads as a screen that failed to load.
 */
/**
 * What to call each family of board in the one flat list a `<select>` can hold.
 *
 * ⚠️ THE PREFIX IS THE WHOLE DISAMBIGUATION. A teacher and a course can share a
 * name, and «الرياضيات» is a plausible label for a subject board AND for a course;
 * two identical-looking options where one crosses workspaces and the other does
 * not is a picker that cannot be used on purpose. `<optgroup>` would say it more
 * cleanly and `SelectField` takes a flat list — worth a prefix, not worth a new
 * variant of a shared control.
 */
const KIND_PREFIX: Record<LeaderboardScopeOption["kind"], string> = {
  platform: "",
  grade: "الصف: ",
  subject: "المادة: ",
  teacher: "المدرّس: ",
  course: "الكورس: ",
  // Never sent by /scopes — a per-lesson board is opened from beside its lesson.
  // Mapped anyway so a future caller passing one is labelled, not left bare.
  lesson: "الدرس: ",
};

export default function LeaderboardPage() {
  const [board, setBoard] = useState<Leaderboard | null>(null);
  // null while the picker is still loading, so "not asked yet" and "asked and
  // there are none" stay distinguishable — they lead to opposite screens.
  const [scopes, setScopes] = useState<LeaderboardScopeOption[] | null>(null);
  const [scope, setScope] = useState<string>("platform");
  const [period, setPeriod] = useState<"week" | "term">("week");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  /*
   * The picker loads once and the board reloads on every change.
   *
   * ⚠️ A FAILURE HERE MUST NOT BLANK THE SCREEN. `platform` is open to every
   * student without any of this, so an empty picker degrades to the board that
   * always exists rather than to an error over a page that would have worked.
   */
  useEffect(() => {
    gamification
      .leaderboardScopes()
      .then((response) => setScopes(response.data))
      // Degrades to the platform board rather than to nothing: `platform` is open
      // to every student without any of this.
      .catch(() => setScopes([{ scope: "platform", label: "المنصّة", kind: "platform" }]));
  }, []);

  /*
   * ⚠️ AN EMPTY LIST IS A TEACHER, AND FETCHING THE BOARD ANYWAY IS A 403.
   *
   * Every scope is closed to them — the cross-workspace three by role, the other
   * three for want of an enrolment they cannot hold in their own workspace. The
   * nav entry is not permission-gated (neither are «تقدّمي» and the shop), so a
   * teacher does reach this page, and before this it answered them with a refusal
   * about a screen that is simply not theirs.
   */
  const forStudentsOnly = scopes !== null && scopes.length === 0;

  const load = useCallback(() => {
    // The picker has to land first. Fired before it, the board request is a 403
    // for every teacher — and the answer to a teacher is a screen, not a refusal.
    if (scopes === null) {
      return;
    }

    if (scopes.length === 0) {
      setLoading(false);

      return;
    }

    setLoading(true);
    setError(null);

    gamification
      .leaderboard(scope, period)
      .then(setBoard)
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, [scope, period, scopes]);

  useEffect(load, [load]);

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">لوحة الصدارة</h1>
          <p className="text-sm text-ink-muted">
            ترتيبك بين طلابٍ في مستواك. تبدأ من جديد كلَّ أسبوع.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          {/* Hidden while there is only the platform board to pick: a select with
              one option is a control that answers nothing. */}
          {(scopes?.length ?? 0) > 1 && (
            <SelectField
              id="scope"
              label="اللوحة"
              value={scope}
              onChange={setScope}
              options={(scopes ?? []).map((option) => ({
                value: option.scope,
                label: KIND_PREFIX[option.kind] + option.label,
              }))}
            />
          )}
          <Button
            variant={period === "week" ? "primary" : "ghost"}
            onClick={() => setPeriod("week")}
          >
            هذا الأسبوع
          </Button>
          {/* The hall of fame, kept separate rather than replacing the weekly
              ranking: a cumulative board makes catching up impossible for anyone
              who joined late, which is what the weekly reset exists to prevent. */}
          <Button
            variant={period === "term" ? "primary" : "ghost"}
            onClick={() => setPeriod("term")}
          >
            قاعة المشاهير
          </Button>
        </div>
      </header>

      {loading && <RowsSkeleton />}

      {!loading && forStudentsOnly && (
        <EmptyState
          title="اللوحات للطلاب"
          description="ترتيبُ طلابك يظهر بجوار أسمائهم في صفحاتهم، لا في لوحةٍ واحدةٍ عندك."
        />
      )}

      {!loading && !forStudentsOnly && error !== null && (
        <ErrorState description={error} onRetry={load} />
      )}

      {!loading && !forStudentsOnly && error === null && board !== null && (
        <>
          {board.my_rank !== null && (
            <Card>
              <p className="text-sm text-ink-muted">ترتيبك</p>
              <p className="text-2xl font-bold text-ink">
                <bdi>{board.my_rank}</bdi>
              </p>
              <p className="text-xs text-ink-muted">
                <bdi>{board.my_points ?? 0}</bdi> نقطة
              </p>
            </Card>
          )}

          {board.entries.length === 0 ? (
            <EmptyState
              title="لا ترتيب بعد هذا الأسبوع"
              description="احضر حصّة أو سلّم واجباً، وسيظهر اسمك هنا."
            />
          ) : (
            <Card>
              <ul className="divide-y divide-line">
                {board.entries.map((row) => (
                  <li
                    key={`${row.rank}-${row.display_name}`}
                    className="flex items-center gap-3 py-2"
                  >
                    <span className="w-8 text-sm font-semibold text-ink-muted">
                      <bdi>{row.rank}</bdi>
                    </span>
                    <span className="flex-1 text-sm text-ink">{row.display_name}</span>
                    <span className="text-sm font-semibold text-ink">
                      <bdi>{row.points}</bdi>
                    </span>
                  </li>
                ))}
              </ul>
              {/* Why a classmate they know is not on the list. Without it, the
                  band reads as a bug. */}
              <p className="mt-3 text-xs text-ink-muted">
                تظهر هنا شريحةٌ من الطلاب في مستوىً قريبٍ من مستواك.
              </p>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
