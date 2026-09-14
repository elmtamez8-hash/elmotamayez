"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { cohorts, type CohortOption } from "@/lib/cohorts";
import { errorCode, userMessage } from "@/lib/errors";

/**
 * «اختر مجموعتك للبدء» — the screen that stands in front of a course taught in
 * groups (FR-028أ).
 *
 * ⚠️ EVERY CARD SHOWS ITS TIMES AND ITS REMAINING PLACES, because choosing
 * between «المجموعة الأولى» and «المجموعة الثانية» is not choosing. The times are
 * what a student is actually picking between, and they are the one fact the
 * group's own row does not hold — the server joins them on.
 *
 * ⚠️ `seats_left: null` IS NOT ZERO AND IS NOT A NUMBER. It means the group
 * declared no ceiling; printed as «٠ مقاعد» it would hide the most open group in
 * the course behind a badge saying it is full.
 *
 * ⚠️ AND A GROUP CAN FILL BETWEEN THE PAINT AND THE TAP. The server answers
 * `cohort_full` and the card takes that refusal — greyed, labelled, and the rest
 * of the list still pressable. Reloading the page instead would throw away the
 * reader's place on a screen whose whole job is comparison.
 */

export function CohortPicker({
  options,
  message,
  onJoined,
}: {
  options: CohortOption[];
  /** The server's sentence — «اختر مجموعتك للبدء», or why there is nothing to pick. */
  message: string | null;
  onJoined: () => void;
}) {
  const [pending, setPending] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  /** Groups this reader has been told are full since the page was painted. */
  const [filled, setFilled] = useState<string[]>([]);

  const join = (uuid: string) => {
    setPending(uuid);
    setError(null);

    cohorts
      .join(uuid)
      .then(onJoined)
      .catch((e: unknown) => {
        // The code, never the Arabic — the wording has to stay editable.
        if (errorCode((e as { body?: unknown })?.body) === "cohort_full") {
          setFilled((current) => [...current, uuid]);
        }

        setError(userMessage(e));
      })
      .finally(() => setPending(null));
  };

  const joinable = options.filter((option) => option.is_joinable && !filled.includes(option.uuid));

  return (
    <Card>
      <div className="space-y-4">
        <div className="space-y-1">
          <h2 className="text-lg font-bold text-ink">مجموعات هذه المادّة</h2>
          {message !== null && <p className="text-sm text-ink-muted">{message}</p>}
        </div>

        {error !== null && <Alert tone="danger" title="تعذّر الانضمام">{error}</Alert>}

        {/*
          ⚠️ **قائمةٌ بلا مجموعةٍ مفتوحةٍ حالة، لا خطأ.** والمنهجُ تحتَ هذه
          البطاقةِ مفتوحٌ دائماً منذُ ٠٣٤ · FR-015، فالجملةُ تقولُ ذلك — و«لا
          توجد مجموعات» وحدَها فوقَ كورسٍ مفتوحٍ تُقرَأُ عُطلاً لا جواباً.

          ⚠️ **والزرُّ فوقُ باقٍ، والجملةُ هنا لا تُقالُ إلّا حينَ لا زرَّ يُضغَط.**
          للطالبِ أن ينضمَّ بنفسِه إلى مجموعةٍ مفتوحةٍ (قرارُ المالكِ ٢٠٢٦-٠٩-١٤،
          بشرطِ ألّا يكونَ في مجموعةٍ أخرى من الكورسِ نفسِه)، وإسنادُ الإدارةِ
          بابٌ **إضافيٌّ** لا بديل. فهذه الفقرةُ للحالةِ الوحيدةِ التي لا فعلَ
          فيها للطالب: لا مجموعةَ مفتوحةً أصلاً.
        */}
        {joinable.length === 0 && (
          <p className="text-sm text-ink-muted">
            لا توجد مجموعة مفتوحة الآن. تابِع المنهج — وهو مفتوح لك — وتُسنِدك الإدارة إلى
            مجموعة حين تُفتح واحدة.
          </p>
        )}

        <ul className="grid gap-3 sm:grid-cols-2">
          {options.map((option) => {
            const full = option.is_full || filled.includes(option.uuid);
            const open = option.is_joinable && !filled.includes(option.uuid);

            return (
              <li
                key={option.uuid}
                className="rounded-2xl border border-line bg-surface p-4 text-start"
              >
                <div className="flex items-start justify-between gap-2">
                  <h3 className="font-semibold text-ink">{option.name}</h3>

                  {/*
                    ⚠️ THE WORD, NOT A COLOUR. «مكتملة» and «مغلقة» are different
                    facts — one may free up and the other will not — and a tint
                    alone says neither.
                  */}
                  {full && <Badge tone="danger">مكتملة</Badge>}
                  {!full && option.status === "closed" && <Badge tone="neutral">مغلقة</Badge>}
                </div>

                {option.description !== null && (
                  <p className="mt-1 text-xs text-ink-muted">{option.description}</p>
                )}

                <p className="mt-2 text-xs text-ink-muted">
                  {option.schedule_preview.length > 0
                    ? option.schedule_preview.join(" · ")
                    : "لم تُجدوَل حصص بعد"}
                </p>

                <p className="mt-1 text-xs text-ink-muted">
                  {option.seats_left === null ? (
                    "بلا حدّ للمقاعد"
                  ) : (
                    <>
                      المقاعد المتبقّية: <bdi>{option.seats_left}</bdi>
                    </>
                  )}
                </p>

                <div className="mt-3">
                  <Button
                    onClick={() => join(option.uuid)}
                    disabled={!open}
                    loading={pending === option.uuid}
                    loadingLabel="جارٍ الانضمام"
                    size="sm"
                  >
                    انضمّ
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>
      </div>
    </Card>
  );
}
