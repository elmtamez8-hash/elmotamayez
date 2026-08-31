"use client";

import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { StudyRoomBoard } from "@/components/practice/StudyRoomBoard";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ApiError } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { studyRooms, type StudyRoomQuestion, type StudyRoomView } from "@/lib/study-rooms";

/**
 * Inside one study room (FR-014 · FR-015).
 *
 * ⚠️ THE PAGE READS FIRST AND JOINS ONLY WHEN REFUSED — see `enter()` below for
 * the two costs of doing it the other way round. Joining is idempotent either
 * way: the link IS the invitation, and somebody coming back after a dropped
 * connection is answered with the seat they already hold. The resume is a row
 * read; nothing is recovered from this browser (SC-007).
 *
 * ⚠️ AND THE REFUSALS ARE READ FROM THE SERVER'S SENTENCE. `room_closed`,
 * `room_full` and `not_eligible` each go through `userMessage()` — the last of
 * them deliberately says nothing about WHICH question was out of reach, because a
 * refusal that explains itself is an oracle over another student's bank.
 */
export default function StudyRoomPage() {
  const params = useParams<{ uuid: string }>();
  const uuid = params.uuid;

  const [view, setView] = useState<StudyRoomView | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [answering, setAnswering] = useState(false);

  /*
    ⚠️ READ FIRST, JOIN ONLY IF REFUSED — AND THE ORDER IS THE WHOLE OF IT.
    Joining on every load has two costs and neither is visible from the code that
    causes it. The HOST takes a seat by looking at their own room, so in a room of
    two the person who shared the link burns one of the seats opening it — and
    `show()`'s host branch, which exists precisely so a host may look in without
    playing, becomes unreachable through the UI. And `POST /join` spends the
    `study-room-write` budget: ten a minute, so a member reloading on a flaky
    connection is answered 429 on their own room — which is the SC-007 person
    exactly.

    A newcomer pays two requests once. Everybody else reads.
  */
  const enter = useCallback(() => {
    setLoading(true);
    setError("");

    studyRooms
      .show(uuid)
      .catch(async (cause: unknown) => {
        // `not_eligible` here means «you are not IN this room» — the same code
        // FR-017 uses, because the server deliberately does not distinguish
        // «not yours» from «not yet yours» to anybody holding the uuid.
        if (!(cause instanceof ApiError) || cause.status !== 403) throw cause;

        await studyRooms.join(uuid);

        return studyRooms.show(uuid);
      })
      .then((response) => setView(response.data))
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(enter, [enter]);

  const answer = async (question: StudyRoomQuestion, optionIds: number[]) => {
    setAnswering(true);
    setError("");

    try {
      const step = (await studyRooms.answer(uuid, question.question_id, optionIds)).data;

      setView((current) =>
        current === null
          ? current
          : {
              ...current,
              room: step.room,
              questions: current.questions.map((item) =>
                item.question_id === question.question_id
                  ? {
                      ...item,
                      answered: true,
                      selected_option_ids: optionIds,
                      is_correct: step.result.is_correct,
                      correct_option_ids: step.result.correct_option_ids,
                      explanation: step.result.explanation,
                    }
                  : item,
              ),
            },
      );
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setAnswering(false);
    }
  };

  if (loading) return <RowsSkeleton />;

  if (view === null) {
    return (
      <div className="space-y-4">
        {error !== "" && <Alert tone="danger" title={error} />}
        <EmptyState
          title="تعذّر الدخول إلى الغرفة"
          description="قد تكون الغرفة انتهت أو اكتمل عدد المشاركين فيها."
          action={<Button onClick={enter}>حاول مرّة أخرى</Button>}
        />
      </div>
    );
  }

  const { room, board, questions } = view;
  const next = questions.find((question) => !question.answered);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-ink">{room.concept?.name ?? "غرفة مذاكرة"}</h1>
          <p className="text-ink-muted">
            مع {room.host.name} · <bdi>{room.question_count}</bdi> أسئلة
          </p>
        </div>
        {/* The label travels beside the value: the state is a comparison against
            the SERVER's clock, never this device's. */}
        <Badge tone={room.state === "closed" ? "neutral" : "info"}>{room.state_label}</Badge>
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}

      <StudyRoomBoard
        roomUuid={room.uuid}
        initial={board}
        questionCount={room.question_count}
        state={room.state}
      />

      <Card>
        {room.state === "closed" ? (
          <EmptyState
            title="انتهت الغرفة"
            description={`نتيجتك ${room.score ?? 0} من ${room.question_count} سؤالاً.`}
          />
        ) : next === undefined ? (
          <EmptyState
            title="أنهيتَ كل الأسئلة"
            description="تابع لوحة النتائج حتى ينتهي وقت الغرفة."
          />
        ) : (
          <Question question={next} disabled={answering} onAnswer={answer} />
        )}
      </Card>

      {questions.some((question) => question.answered) && (
        <Card>
          <h2 className="mb-4 font-semibold text-ink">ما أجبتَ عنه</h2>
          <ul className="space-y-3">
            {questions
              .filter((question) => question.answered)
              .map((question) => (
                <li key={question.question_id} className="rounded-lg border border-line p-3">
                  <p className="text-sm text-ink">{question.content}</p>
                  <p className="mt-1 text-xs text-ink-muted">
                    <Badge tone={question.is_correct === true ? "success" : "danger"}>
                      {question.is_correct === true ? "إجابة صحيحة" : "إجابة خاطئة"}
                    </Badge>
                  </p>
                  {typeof question.explanation === "string" && question.explanation !== "" && (
                    <p className="mt-2 text-xs text-ink-muted">{question.explanation}</p>
                  )}
                </li>
              ))}
          </ul>
        </Card>
      )}
    </div>
  );
}

/**
 * ⚠️ THE SELECTION IS REPLACED, NEVER ACCUMULATED. Every answering surface in this
 * product is single-select — a question carries exactly one correct option — and
 * the two that used to add rather than replace turned a right answer into a zero
 * on the second tap.
 */
function Question({
  question,
  disabled,
  onAnswer,
}: {
  question: StudyRoomQuestion;
  disabled: boolean;
  onAnswer: (question: StudyRoomQuestion, optionIds: number[]) => void;
}) {
  const [selected, setSelected] = useState<number | null>(null);

  // A fresh question is a fresh selection: carrying the last one over would
  // pre-answer the next question with somebody's previous tap.
  useEffect(() => setSelected(null), [question.question_id]);

  return (
    <div className="space-y-4">
      <p className="text-sm font-medium text-ink">
        <bdi>{question.order}</bdi>. {question.content}
      </p>

      <ul className="space-y-2">
        {question.options.map((option) => (
          <li key={option.id}>
            <label className="flex cursor-pointer items-center gap-3 rounded-lg border border-line p-3 text-sm text-ink has-checked:border-primary">
              <input
                type="radio"
                name={`question-${question.question_id}`}
                value={option.id}
                checked={selected === option.id}
                onChange={() => setSelected(option.id)}
                disabled={disabled}
              />
              <span>{option.content}</span>
            </label>
          </li>
        ))}
      </ul>

      <Button
        onClick={() => onAnswer(question, selected === null ? [] : [selected])}
        disabled={disabled}
      >
        {disabled ? "جارٍ التصحيح…" : "أجب"}
      </Button>
    </div>
  );
}
