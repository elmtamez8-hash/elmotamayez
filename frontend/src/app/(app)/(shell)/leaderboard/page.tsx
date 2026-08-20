"use client";

import { useCallback, useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { gamification, type Leaderboard } from "@/lib/gamification";

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
const SCOPES = [
  { key: "platform", label: "المنصّة" },
] as const;

export default function LeaderboardPage() {
  const [board, setBoard] = useState<Leaderboard | null>(null);
  const [scope, setScope] = useState<string>("platform");
  const [period, setPeriod] = useState<"week" | "term">("week");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    gamification
      .leaderboard(scope, period)
      .then(setBoard)
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, [scope, period]);

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
        <div className="flex flex-wrap gap-2">
          {SCOPES.map((option) => (
            <Button
              key={option.key}
              variant={scope === option.key ? "primary" : "ghost"}
              onClick={() => setScope(option.key)}
            >
              {option.label}
            </Button>
          ))}
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
      {!loading && error !== null && <ErrorState description={error} onRetry={load} />}

      {!loading && error === null && board !== null && (
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
