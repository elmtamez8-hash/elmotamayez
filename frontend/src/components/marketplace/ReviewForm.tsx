"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, errorMessage, hasAuthToken } from "@/lib/api";
import { Button } from "@/components/ui/Button";

const RATINGS = [5, 4, 3, 2, 1] as const;

/**
 * The review form, shown to a signed-in student.
 *
 * Eligibility — "did you actually finish a session with this teacher" — is not
 * something the browser can know, so this does not try to guess it. It submits
 * and shows what the API says; the server holds the rule (FR-018). A guessed
 * "you are not eligible" would be wrong for anyone whose enrollment the client
 * has not loaded.
 */
export function ReviewForm({ teacherUuid }: { teacherUuid: string }) {
  const router = useRouter();

  const [rating, setRating] = useState<number>(5);
  const [comment, setComment] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  // null until mounted: localStorage does not exist during the server render, and
  // deciding on the server would ship the wrong branch in the crawlable HTML.
  const [signedIn, setSignedIn] = useState<boolean | null>(null);

  useEffect(() => setSignedIn(hasAuthToken()), []);

  if (signedIn === null) return null;

  if (!signedIn) {
    return (
      <p className="rounded-xl border border-line bg-primary-soft/60 p-4 text-sm text-ink-muted">
        <Link href="/login" className="font-semibold text-primary-ink hover:underline">
          سجّل الدخول
        </Link>{" "}
        لتترك تقييماً بعد إتمام حصة مع هذا المدرّس.
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

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await api.post(`/teachers/${teacherUuid}/reviews`, {
        rating,
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

      <form onSubmit={submit} className="space-y-4" noValidate>
        {/* A radio group, not five buttons: keyboard users get arrow-key selection
            and a screen reader announces "3 of 5" without extra ARIA. */}
        <fieldset>
          <legend className="mb-2 text-sm font-medium text-ink">التقييم</legend>
          <div className="flex flex-wrap gap-2">
            {RATINGS.map((value) => (
              <label
                key={value}
                className={`cursor-pointer rounded-xl border px-4 py-2 text-sm font-semibold transition has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-primary ${
                  rating === value
                    ? "border-primary bg-primary-soft text-primary-ink"
                    : "border-line text-ink-muted hover:border-primary/50"
                }`}
              >
                <input
                  type="radio"
                  name="rating"
                  value={value}
                  checked={rating === value}
                  onChange={() => setRating(value)}
                  className="sr-only"
                />
                {value} ★
              </label>
            ))}
          </div>
        </fieldset>

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

        <Button type="submit" variant="accent" size="lg" fullWidth loading={submitting}>إرسال التقييم</Button>
      </form>
    </section>
  );
}
