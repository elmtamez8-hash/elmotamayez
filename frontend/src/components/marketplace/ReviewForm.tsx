"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, errorMessage, hasAuthToken } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { AxisScale } from "@/components/ui/AxisScale";
import { reviews, TEACHER_AXES, type ReviewEligibility } from "@/lib/reviews";

/**
 * The review form, shown to a signed-in student (spec 010 · FR-030 · FR-031).
 *
 * ⚠️ ELIGIBILITY IS ASKED, NOT GUESSED — and the endpoint it asks is derived from
 * the same object the submit endpoint refuses with. The rule is «enough sessions
 * counted as attended, inside a period», which the browser cannot know: a guess
 * would be wrong for anyone whose attendance it has not loaded, and a rule
 * reimplemented here would offer a form the server rejects or hide one it accepts.
 * That is the `ListLeaderboardScopes` defect, and `SC-010` measures it.
 *
 * ⚠️ AND THERE IS NO OVERALL STAR TO PICK. The public rating is the AVERAGE of the
 * three axes, computed on the server — a fourth control here would be a second,
 * disagreeing answer, and the star on the profile would stop matching the bars
 * under it.
 */
export function ReviewForm({ teacherUuid }: { teacherUuid: string }) {
  const router = useRouter();

  const [axes, setAxes] = useState<Record<string, number>>({
    punctuality: 5,
    clarity: 5,
    engagement: 5,
  });
  const [comment, setComment] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  // null until mounted: localStorage does not exist during the server render, and
  // deciding on the server would ship the wrong branch in the crawlable HTML.
  const [signedIn, setSignedIn] = useState<boolean | null>(null);
  const [standing, setStanding] = useState<ReviewEligibility | null>(null);

  useEffect(() => setSignedIn(hasAuthToken()), []);

  useEffect(() => {
    if (signedIn !== true) return;

    reviews
      .eligibility(teacherUuid)
      // A failure here leaves `standing` null and the form open: the server is
      // still the gate, so the worst case is a refusal the student reads after
      // submitting rather than before.
      .then(setStanding)
      .catch(() => undefined);
  }, [signedIn, teacherUuid]);

  if (signedIn === null) return null;

  if (!signedIn) {
    return (
      <p className="rounded-xl border border-line bg-primary-soft/60 p-4 text-sm text-ink-muted">
        <Link href="/login" className="font-semibold text-primary-ink hover:underline">
          سجّل الدخول
        </Link>{" "}
        لتترك تقييماً بعد حضور عددٍ من الحصص مع هذا المدرّس.
      </p>
    );
  }

  if (done) {
    return (
      <p
        role="status"
        className="rounded-xl border border-secondary/40 bg-secondary/10 p-4 text-sm font-medium text-ink"
      >
        شكراً لتقييمك — يظهر رأيك على الملف خلال دقائق.
      </p>
    );
  }

  // ⚠️ THE SERVER'S OWN SENTENCE, NOT ONE COMPOSED HERE. It names how many
  // sessions are needed and how many the student has — «you may not» leaves
  // someone two lessons away with nothing to act on.
  if (standing !== null && !standing.eligible) {
    return (
      <p className="rounded-xl border border-line bg-primary-soft/60 p-4 text-sm text-ink-muted">
        {standing.reason ?? "لا يمكن التقييم بعد."}
      </p>
    );
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await api.post(`/teachers/${teacherUuid}/reviews`, {
        ...axes,
        comment: comment.trim() === "" ? null : comment.trim(),
      });
      setDone(true);
      // The average and the distribution are server-rendered, so the page has to
      // be refetched for the new review to appear in them.
      router.refresh();
    } catch (err) {
      setError(errorMessage(err, "تعذّر إرسال التقييم. حاول مرة أخرى."));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section aria-labelledby="review-form-heading" className="rounded-2xl border border-line p-6">
      <h2 id="review-form-heading" className="mb-4 text-lg font-bold text-ink">
        قيّم هذا المدرّس
      </h2>

      {standing?.is_revision === true && (
        <p className="mb-4 text-sm text-ink-muted">
          لك تقييمٌ في هذه الفترة — الإرسال يحدّثه ولا يضيف تقييماً ثانياً.
        </p>
      )}

      <form onSubmit={submit} className="space-y-4" noValidate>
        {TEACHER_AXES.map((axis) => (
          <AxisScale
            key={axis.key}
            name={axis.key}
            label={axis.label}
            value={axes[axis.key]}
            onChange={(value) => setAxes((current) => ({ ...current, [axis.key]: value }))}
            disabled={submitting}
          />
        ))}

        <div>
          <label htmlFor="review-comment" className="mb-1 block text-sm font-medium text-ink">
            تعليقك (اختياري)
          </label>
          <textarea
            id="review-comment"
            value={comment}
            onChange={(event) => setComment(event.target.value)}
            rows={4}
            maxLength={2000}
            className="w-full rounded-xl border border-line bg-surface px-3 py-2.5 text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
            placeholder="ما الذي أعجبك في أسلوب الشرح؟"
          />
        </div>

        {error && (
          <p role="alert" className="text-sm text-danger-ink">
            {error}
          </p>
        )}

        <Button type="submit" variant="accent" size="lg" fullWidth loading={submitting}>
          إرسال التقييم
        </Button>
      </form>
    </section>
  );
}
