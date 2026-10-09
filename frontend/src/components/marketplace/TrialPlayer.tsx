"use client";

import { useEffect, useState } from "react";

import { EmbeddedVideo } from "@/components/player/EmbeddedVideo";
import { VideoPlayerCore, type PlayerSource } from "@/components/player/VideoPlayer";
import { Alert } from "@/components/ui/Alert";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import type { CourseTrial } from "@/lib/public-api";

/**
 * A course's «حصة تجريبية» for anybody (spec 040).
 *
 * ⚠️ FETCHED HERE, IN THE BROWSER, never by the page on the server: the trial
 * door is limited per visitor address, and a server-side fetch would put every
 * visitor in the Next server's single bucket (design review H1/M4).
 *
 * ⚠️ `VideoPlayerCore`, NEVER `VideoPlayer`. The student player mounts the
 * watermark, which renews a grant every minute and stops playback when it
 * cannot — and a guest has no grant. No renewal is ever sent from here; the
 * link is kept fresh by the core reloading through our stream route at the
 * server's `reload_after_seconds`.
 */
export function TrialPlayer({ courseKey }: { courseKey: string }) {
  const [trial, setTrial] = useState<CourseTrial | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let live = true;

    api
      .get<{ data: CourseTrial }>(`/marketplace/courses/${encodeURIComponent(courseKey)}/trial`)
      .then((answer) => live && setTrial(answer.data))
      .catch((err: unknown) => live && setError(userMessage(err)));

    return () => {
      live = false;
    };
  }, [courseKey]);

  if (error !== "") {
    return (
      <Alert tone="warning" title="تعذّر تشغيل الحصة التجريبية">
        {error}
      </Alert>
    );
  }

  if (trial === null) return <RowsSkeleton count={1} />;

  if (trial.kind === "embed" && trial.embed_url !== undefined) {
    return (
      <EmbeddedVideo
        embedUrl={trial.embed_url}
        title={trial.title}
        report={{ courseKey: trial.course.slug, lessonUuid: trial.uuid }}
      />
    );
  }

  if (trial.playback === undefined) return null;

  const source: PlayerSource = {
    manifest_url: trial.playback.manifest_url,
    format: trial.playback.format,
    reload_after_seconds: trial.playback.reload_after_seconds,
    captions: [],
    resume_at_seconds: 0,
  };

  return <VideoPlayerCore source={source} />;
}
