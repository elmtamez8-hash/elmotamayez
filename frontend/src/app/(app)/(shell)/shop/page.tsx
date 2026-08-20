"use client";

import { useCallback, useEffect, useState } from "react";

import { RedeemButton } from "@/components/gamification/RedeemButton";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import {
  gamification,
  type CoinPurse,
  type Redemption,
  type Reward,
} from "@/lib/gamification";

/**
 * The teacher's shop, and what this student has claimed from it.
 *
 * ⚠️ ONE TEACHER AT A TIME, AND THE SWITCHER IS THE POINT. Coins are earned and
 * spent per teacher, so a single merged shop would offer things the student's
 * purse for that teacher cannot buy — and the refusal would arrive only after
 * they clicked. The purse for the selected teacher is shown beside the list for
 * the same reason.
 */
export default function ShopPage() {
  const [purses, setPurses] = useState<CoinPurse[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [rewards, setRewards] = useState<Reward[]>([]);
  const [mine, setMine] = useState<Redemption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadPurses = useCallback(() => {
    setLoading(true);
    setError(null);

    Promise.all([gamification.me(), gamification.myRedemptions()])
      .then(([progress, redemptions]) => {
        const withWorkspace = progress.coin_balances.filter(
          (purse): purse is CoinPurse & { workspace_uuid: string } => purse.workspace_uuid !== null,
        );

        setPurses(withWorkspace);
        setMine(redemptions.data ?? []);
        setSelected((current) => current ?? withWorkspace[0]?.workspace_uuid ?? null);
      })
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(loadPurses, [loadPurses]);

  const loadRewards = useCallback(() => {
    if (selected === null) return;

    gamification
      .shop(selected)
      .then((response) => setRewards(response.data ?? []))
      .catch((cause) => setError(userMessage(cause)));
  }, [selected]);

  useEffect(loadRewards, [loadRewards]);

  if (loading) return <RowsSkeleton />;
  if (error !== null) return <ErrorState description={error} onRetry={loadPurses} />;

  const purse = purses.find((row) => row.workspace_uuid === selected);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">متجر المكافآت</h1>
        <p className="text-sm text-ink-muted">
          استبدل عملاتك بمكافآت من مدرّسك. عملات كلّ مدرّسٍ تُنفَق في متجره وحده.
        </p>
      </header>

      {purses.length === 0 ? (
        <EmptyState
          title="لا عملات بعد"
          description="تُكتسب العملات بحضورك وحلّ واجباتك، ثم تُنفَق هنا."
        />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-2">
            {purses.map((row) => (
              <Button
                key={row.workspace_uuid}
                variant={selected === row.workspace_uuid ? "primary" : "ghost"}
                onClick={() => setSelected(row.workspace_uuid)}
              >
                {row.teacher_name} · <bdi>{row.coins}</bdi>
              </Button>
            ))}
          </div>

          {rewards.length === 0 ? (
            <EmptyState
              title="لا مكافآت معروضة"
              description="لم يضِف هذا المدرّس مكافآت بعد."
            />
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {rewards.map((reward) => {
                const affordable = (purse?.coins ?? 0) >= reward.price_coins;

                return (
                  <Card key={reward.uuid}>
                    <div className="space-y-3">
                      <div>
                        <h2 className="text-base font-semibold text-ink">{reward.title}</h2>
                        <Badge tone="neutral">{reward.type_label_ar}</Badge>
                      </div>
                      <p className="text-sm text-ink">
                        السعر: <bdi>{reward.price_coins}</bdi> عملة
                      </p>
                      {reward.stock === 0 ? (
                        <p className="text-sm text-ink-muted">نفد المخزون.</p>
                      ) : (
                        <>
                          <RedeemButton
                            rewardUuid={reward.uuid}
                            disabled={!affordable}
                            onRedeemed={() => {
                              loadPurses();
                              loadRewards();
                            }}
                          />
                          {/* Said before the click rather than after it. The
                              server would refuse either way; being told why in
                              advance is the difference between a shop and a
                              guessing game. */}
                          {!affordable && (
                            <p className="text-xs text-ink-muted">عملاتك عند هذا المدرّس لا تكفي بعد.</p>
                          )}
                        </>
                      )}
                    </div>
                  </Card>
                );
              })}
            </div>
          )}
        </>
      )}

      <Card>
        <h2 className="mb-3 text-base font-semibold text-ink">طلباتي</h2>
        {mine.length === 0 ? (
          <EmptyState title="لا طلبات بعد" description="ما تستبدله يظهر هنا بحالته." />
        ) : (
          <ul className="divide-y divide-line">
            {mine.map((redemption) => (
              <li key={redemption.uuid} className="flex items-center justify-between gap-3 py-2">
                <span className="text-sm text-ink">{redemption.reward?.title ?? "مكافأة"}</span>
                <span className="flex items-center gap-2">
                  <span className="text-xs text-ink-muted">
                    {redemption.created_at === null ? "" : formatDate(redemption.created_at)}
                  </span>
                  <Badge
                    tone={
                      redemption.status === "fulfilled"
                        ? "success"
                        : redemption.status === "rejected"
                          ? "danger"
                          : "warning"
                    }
                  >
                    {redemption.status_label_ar}
                  </Badge>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
