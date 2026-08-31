"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import type { AdaptiveDifficulty } from "@/lib/adaptive";
import { userMessage } from "@/lib/errors";
import { difficultyLabel } from "@/lib/labels";
import { practice, type PracticeFilterOptions } from "@/lib/practice";
import { studyRooms, type StudyRoom } from "@/lib/study-rooms";

/**
 * Open a study room, or go back into one (spec 012 · US3).
 *
 * ⚠️ THE PICKERS COME FROM `/practice/filters`, THE SAME POOL THE ROOM'S PAPER IS
 * DRAWN FROM. A list assembled beside the query it filters is the defect spec
 * 009's leaderboard picker paid for: options the API then refuses, and options it
 * would have allowed left out.
 *
 * ⚠️ AND «I ASKED FOR TWENTY AND GOT SIX» IS SAID OUT LOUD. FR-023 makes a short
 * paper an answer rather than a failure — but only if the student is told, or
 * they get a room they did not ask for with no reason given.
 */
export default function StudyRoomsPage() {
  const [rooms, setRooms] = useState<StudyRoom[] | null>(null);
  const [options, setOptions] = useState<PracticeFilterOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [creating, setCreating] = useState(false);

  const [teacher, setTeacher] = useState("");
  const [concept, setConcept] = useState("");
  const [difficulty, setDifficulty] = useState<AdaptiveDifficulty | "">("");
  const [count, setCount] = useState("10");
  const [seats, setSeats] = useState("10");
  const [duration, setDuration] = useState("15");

  useEffect(() => {
    setLoading(true);

    Promise.all([studyRooms.mine(), practice.filters()])
      .then(([mine, filters]) => {
        setRooms(mine.data);
        setOptions(filters.data);
      })
      // ⚠️ A SENTENCE, NEVER A BLANK PAGE. Swallowing the rejection renders an
      // empty screen with the reason sitting unread in the response.
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  // Concepts belong to ONE bank, so a second teacher offers a different list.
  useEffect(() => {
    if (teacher === "") return;

    setConcept("");

    practice
      .filters(teacher)
      .then((response) => setOptions(response.data))
      .catch((cause: unknown) => setError(userMessage(cause)));
  }, [teacher]);

  const open = async () => {
    setCreating(true);
    setError("");
    setNotice("");

    try {
      const created = (
        await studyRooms.create({
          teacher: teacher === "" ? (options?.teachers[0]?.uuid ?? "") : teacher,
          concept: concept === "" ? undefined : concept,
          difficulty: difficulty === "" ? undefined : difficulty,
          question_count: Number(count),
          max_participants: Number(seats),
          duration_minutes: Number(duration),
          starts_in_minutes: 2,
        })
      ).data;

      if (created.requested_count !== undefined && created.requested_count > created.question_count) {
        setNotice(
          `طلبتَ ${created.requested_count} سؤالاً والمتاح لك ${created.question_count}، فبُنيت الغرفة بالمتاح.`,
        );
      }

      setRooms((current) => [created, ...(current ?? [])]);
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setCreating(false);
    }
  };

  if (loading) return <RowsSkeleton />;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-ink">غرف المذاكرة</h1>
        <p className="text-ink-muted">
          افتح غرفةً وشارك رابطها مع أصدقائك، فتحلّون المجموعة نفسها في الوقت نفسه بلوحة نتائج
          لحظية.
        </p>
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}
      {/* FR-023 said out loud: a short paper is an answer, and an answer nobody
          is told is a room the student did not ask for with no reason given. */}
      {notice !== "" && <Alert tone="warning" title={notice} />}

      <Card>
        <h2 className="mb-4 font-semibold text-ink">غرفة جديدة</h2>
        {options !== null && !options.has_questions ? (
          <EmptyState
            title="لا أسئلة متاحة لك بعد"
            description="ستظهر غرف المذاكرة حين يضيف مدرّسك أسئلةً إلى دروسك."
          />
        ) : (
          <div className="space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <SelectField
                id="room_teacher"
                label="المدرّس"
                value={teacher}
                onChange={setTeacher}
                options={(options?.teachers ?? []).map((item) => ({
                  value: item.uuid,
                  label: item.label,
                }))}
                placeholder="اختر المدرّس"
              />
              <SelectField
                id="room_concept"
                label="الفكرة"
                value={concept}
                onChange={setConcept}
                options={(options?.concepts ?? []).map((item) => ({
                  value: item.uuid,
                  label: item.label,
                }))}
                placeholder="كل الأفكار"
              />
              <SelectField
                id="room_difficulty"
                label="الصعوبة"
                value={difficulty}
                onChange={(value) => setDifficulty(value as AdaptiveDifficulty | "")}
                options={(["easy", "medium", "hard"] as const).map((value) => ({
                  value,
                  label: difficultyLabel(value),
                }))}
                placeholder="مختلطة"
              />
              <NumberField
                id="question_count"
                label="عدد الأسئلة"
                value={count}
                onChange={setCount}
                min={1}
                max={30}
              />
              <NumberField
                id="max_participants"
                label="عدد المشاركين"
                value={seats}
                onChange={setSeats}
                min={1}
                max={30}
              />
              <NumberField
                id="duration_minutes"
                label="المدة بالدقائق"
                value={duration}
                onChange={setDuration}
                min={1}
                max={180}
              />
            </div>

            <Button onClick={open} disabled={creating}>
              {creating ? "جارٍ الفتح…" : "افتح الغرفة"}
            </Button>
          </div>
        )}
      </Card>

      <Card>
        <h2 className="mb-4 font-semibold text-ink">غرفي</h2>
        {rooms === null || rooms.length === 0 ? (
          <EmptyState
            title="لا غرف بعد"
            description="افتح غرفةً من الأعلى، أو ادخل برابطٍ وصلك من صديق."
          />
        ) : (
          <ul className="space-y-3">
            {rooms.map((room) => (
              <li
                key={room.uuid}
                className="flex items-center justify-between gap-4 rounded-lg border border-line p-3"
              >
                <span className="min-w-0">
                  <Link
                    href={`/study-rooms/${room.uuid}`}
                    className="truncate rounded text-sm font-medium text-ink hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    {room.concept?.name ?? "كل الأفكار"} · {room.host.name}
                  </Link>
                  <span className="block text-xs text-ink-muted">
                    <bdi>{room.question_count}</bdi> أسئلة ·{" "}
                    <bdi>{room.duration_minutes}</bdi> دقيقة
                  </span>
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  {room.score !== null && (
                    <span className="text-xs text-ink-muted">
                      <bdi>
                        {room.answered}/{room.question_count}
                      </bdi>
                    </span>
                  )}
                  {/* ⚠️ The label comes from the server beside the value: the
                      state is a comparison against ITS clock, not the browser's. */}
                  <Badge tone={room.state === "closed" ? "neutral" : "info"}>
                    {room.state_label}
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
