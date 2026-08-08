"use client";

import { use, useEffect, useState } from "react";

import { AttachmentList, type StudentAttachment } from "@/components/player/AttachmentList";
import { DocumentViewer } from "@/components/player/DocumentViewer";
import { VideoPlayer } from "@/components/player/VideoPlayer";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ApiError, api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { media, type PlaybackGrant } from "@/lib/media";

interface StudentLesson {
  uuid: string;
  title: string;
  type: string;
  type_label: string;
  is_completable: boolean;
  content: string | null;
  content_html: string;
  external_url: string | null;
  has_asset: boolean;
  attachments: StudentAttachment[];
}

/**
 * Opening one item.
 *
 * It played video and nothing else, so an article rendered as an empty player
 * and a PDF was unreachable — the enrolments page worked around it by refusing
 * to link anything but video, which is a workaround, not a fix.
 *
 * Whatever the type, a grant is requested on demand and never stored anywhere
 * durable: it expires in minutes, is bound to this sign-in, and dies the moment
 * the session does. A URL copied from the network tab is worth nothing to
 * anyone else, which is the whole point of the phase.
 */
export default function LearnLessonPage({
  params,
}: {
  params: Promise<{ lesson: string }>;
}) {
  const { lesson } = use(params);

  const [detail, setDetail] = useState<StudentLesson | null>(null);
  const [grant, setGrant] = useState<PlaybackGrant | null>(null);
  const [error, setError] = useState("");
  const [preparing, setPreparing] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    api
      .get<{ lesson: StudentLesson; can_access: boolean }>(`/learn/lessons/${lesson}`)
      .then((result) => {
        if (!cancelled) setDetail(result.can_access ? result.lesson : null);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [lesson]);

  // The player is asked for only when there is something to play. Requesting a
  // grant for an article would burn one and answer 403 for the right reason at
  // the wrong time.
  const wantsPlayer = detail === null || detail.type === "video" || detail.type === "audio";

  useEffect(() => {
    let cancelled = false;

    if (!wantsPlayer) {
      setLoading(false);

      return;
    }

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
  }, [lesson, wantsPlayer]);

  const isDocument = detail !== null && (detail.type === "pdf" || detail.type === "file");

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-xl font-bold text-ink">{detail?.title ?? "الدرس"}</h1>
      {detail !== null && (
        <p className="-mt-4 text-sm text-ink-muted">
          {detail.type_label}
          {!detail.is_completable && " · لا يُحتسب في نسبة تقدّمك"}
        </p>
      )}

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

      {grant !== null && wantsPlayer && (
        <Card padding="sm">
          <VideoPlayer grant={grant} />
        </Card>
      )}

      {isDocument && detail !== null && (
        <Card padding="sm">
          <DocumentViewer lessonUuid={lesson} filename={detail.title} />
        </Card>
      )}

      {detail !== null && (detail.type === "article" || detail.type === "note") && (
        <Card>
          {/* Rendered from Markdown on the server with raw HTML stripped, not
              escaped — the same string every reader is served. The page used to
              show the SOURCE, asterisks and all. */}
          <div
            className="text-ink"
            dangerouslySetInnerHTML={{ __html: detail.content_html }}
          />
        </Card>
      )}

      {detail !== null && detail.type === "link" && detail.external_url !== null && (
        <Card>
          <p className="mb-3 text-sm text-ink-muted">
            هذا المحتوى على موقع خارجي — خارج حماية المنصّة، ولا يُحتسب في نسبة تقدّمك.
          </p>
          <Button href={detail.external_url} external>
            فتح الرابط
          </Button>
        </Card>
      )}

      {detail !== null && (
        <AttachmentList lessonUuid={lesson} attachments={detail.attachments} />
      )}
    </div>
  );
}
