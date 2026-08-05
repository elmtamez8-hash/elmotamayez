"use client";

import { use, useEffect, useState } from "react";

import { VideoPlayer } from "@/components/player/VideoPlayer";
import { Alert } from "@/components/ui/Alert";
import { Card } from "@/components/ui/Card";
import { ApiError } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { media, type PlaybackGrant } from "@/lib/media";

/**
 * Watching one lesson.
 *
 * The grant is requested on mount and never stored anywhere durable: it expires
 * in minutes, is bound to this sign-in, and dies the moment the session does. A
 * copied URL from the network tab is worth nothing to anyone else, which is the
 * whole point of the phase.
 */
export default function LearnLessonPage({
  params,
}: {
  params: Promise<{ lesson: string }>;
}) {
  const { lesson } = use(params);

  const [grant, setGrant] = useState<PlaybackGrant | null>(null);
  const [error, setError] = useState("");
  const [preparing, setPreparing] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    media
      .requestPlayback(lesson)
      .then((result) => {
        if (!cancelled) setGrant(result);
      })
      .catch((err: unknown) => {
        if (cancelled) return;

        // 409 is not a failure: the viewer is entitled and the video is still
        // being prepared. Showing it as an error would send them to support over
        // something that fixes itself.
        if (err instanceof ApiError && err.status === 409) setPreparing(true);
        else setError(userMessage(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [lesson]);

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-xl font-bold text-ink">مشاهدة الدرس</h1>

      {loading && <p className="text-sm text-ink-muted">جارٍ التحضير…</p>}

      {preparing && (
        <Alert tone="info" title="الفيديو قيد التجهيز">
          الفيديو ما زال قيد المعالجة. حدّث الصفحة بعد قليل.
        </Alert>
      )}

      {error !== "" && (
        <Alert tone="danger" title="تعذّرت المشاهدة">
          {error}
        </Alert>
      )}

      {grant !== null && (
        <Card padding="sm">
          <VideoPlayer grant={grant} />
        </Card>
      )}
    </div>
  );
}
