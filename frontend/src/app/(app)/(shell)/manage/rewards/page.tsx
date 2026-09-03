"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { gamification, type Redemption, type Reward } from "@/lib/gamification";

/**
 * The teacher's shop and the queue of what students have claimed.
 *
 * ⚠️ THE MONTHLY CAP IS MANDATORY FOR A REWARD THAT COSTS MONEY, and the server
 * says so under the field rather than in a banner: without a cap, gamification
 * stops being an engagement budget and becomes an open marketing expense. The
 * form marks it required for those types, but the enforcement is the Action —
 * this is only the door the browser uses.
 */
const TYPES = [
  { value: "printed", label: "نسخة مطبوعة", costsMoney: true },
  { value: "discount", label: "خصم على حصة", costsMoney: true },
  { value: "deadline_extension", label: "تأجيل تسليم واجب", costsMoney: false },
  { value: "streak_shield", label: "درع حماية السلسلة", costsMoney: false },
] as const;

export default function ManageRewardsPage() {
  const [rewards, setRewards] = useState<Reward[]>([]);
  const [queue, setQueue] = useState<Redemption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const [title, setTitle] = useState("");
  const [price, setPrice] = useState(50);
  const [stock, setStock] = useState(10);
  const [type, setType] = useState<string>("printed");
  const [cap, setCap] = useState<number | "">(5);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    Promise.all([gamification.manage.rewards(), gamification.manage.redemptions("pending")])
      .then(([list, pending]) => {
        setRewards(list.data ?? []);
        setQueue(pending.data ?? []);
      })
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    gamification.manage
      .saveReward({
        title,
        price_coins: price,
        stock,
        reward_type: type,
        monthly_cap: cap === "" ? null : cap,
      })
      .then(() => {
        setTitle("");
        load();
      })
      // 422 lands under its field; anything else becomes a sentence.
      .catch((cause) => {
        setErrors(fieldErrors(cause));
        setError(userMessage(cause));
      })
      .finally(() => setBusy(false));
  };

  const decide = (uuid: string, outcome: "fulfill" | "reject") => {
    setBusy(true);

    const action = outcome === "fulfill" ? gamification.manage.fulfill : gamification.manage.reject;

    action(uuid)
      .then(load)
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setBusy(false));
  };

  if (loading) return <RowsSkeleton />;

  const selected = TYPES.find((option) => option.value === type);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">مكافآت الطلاب</h1>
        <p className="text-sm text-ink-muted">
          ما يستطيع طلابك استبداله بعملاتهم، وطلبات الاستبدال المنتظرة.
        </p>
      </header>

      {error !== null && <ErrorState description={error} onRetry={load} />}

      <Card>
        <h2 className="mb-3 text-base font-semibold text-ink">طلبات منتظرة</h2>
        {queue.length === 0 ? (
          <EmptyState title="لا طلبات منتظرة" description="ما يستبدله طلابك يظهر هنا." />
        ) : (
          <ul className="divide-y divide-line">
            {queue.map((redemption) => (
              <li key={redemption.uuid} className="flex flex-wrap items-center gap-3 py-2">
                <span className="flex-1 text-sm text-ink">
                  {redemption.student_name ?? "طالب"} — {redemption.reward?.title ?? "مكافأة"}
                </span>
                <Button onClick={() => decide(redemption.uuid, "fulfill")} disabled={busy}>
                  نُفِّذ
                </Button>
                {/* Rejecting returns the coins in full, so it is a ghost button
                    rather than a danger one: it is a correction, not a penalty. */}
                <Button
                  variant="ghost"
                  onClick={() => decide(redemption.uuid, "reject")}
                  disabled={busy}
                >
                  ارفض وأعِد العملات
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <h2 className="mb-3 text-base font-semibold text-ink">مكافأة جديدة</h2>
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm text-ink">
            العنوان
            <input
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              required
              className="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"
            />
          </label>

          <label className="text-sm text-ink">
            نوع المكافأة
            <select
              value={type}
              onChange={(event) => setType(event.target.value)}
              className="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"
            >
              {TYPES.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="text-sm text-ink">
            السعر بالعملات
            <input
              type="number"
              min={1}
              value={price}
              onChange={(event) => setPrice(Number(event.target.value))}
              className="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"
            />
          </label>

          <label className="text-sm text-ink">
            المخزون
            <input
              type="number"
              min={0}
              value={stock}
              onChange={(event) => setStock(Number(event.target.value))}
              className="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"
            />
          </label>

          <label className="text-sm text-ink sm:col-span-2">
            السقف الشهري
            <input
              type="number"
              min={1}
              value={cap}
              required={selected?.costsMoney ?? false}
              onChange={(event) =>
                setCap(event.target.value === "" ? "" : Number(event.target.value))
              }
              className="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"
            />
            <span className="mt-1 block text-xs text-ink-muted">
              {selected?.costsMoney
                ? "إلزاميّ للمكافآت ذات الكلفة: أكبر عددٍ من الطلاب يستبدلها في الشهر."
                : "اتركه فارغاً فلا سقف."}
            </span>
            {errors.monthly_cap !== undefined && (
              <span className="mt-1 block text-sm text-danger-ink">{errors.monthly_cap}</span>
            )}
          </label>

          <div className="sm:col-span-2">
            <Button type="submit" disabled={busy}>
              أضِف المكافأة
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <h2 className="mb-3 text-base font-semibold text-ink">المكافآت المتاحة</h2>
        {rewards.length === 0 ? (
          <EmptyState title="لا مكافآت بعد" description="أضِف أوّل مكافأةٍ من النموذج أعلاه." />
        ) : (
          <ul className="divide-y divide-line">
            {rewards.map((reward) => (
              <li key={reward.uuid} className="flex flex-wrap items-center gap-3 py-2">
                <span className="flex-1 text-sm text-ink">{reward.title}</span>
                <Badge tone="neutral">{reward.type_label_ar}</Badge>
                <span className="text-sm text-ink-muted">
                  <bdi>{reward.price_coins}</bdi> عملة · مخزون <bdi>{reward.stock}</bdi>
                </span>
                {!reward.is_active && <Badge tone="warning">معطَّلة</Badge>}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
