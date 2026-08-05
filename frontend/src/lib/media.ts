import { api } from "./api";

/**
 * The video pipeline's client side.
 *
 * There is no "video URL" here, and there cannot be: what the server hands back
 * is a grant that expires, is bound to this session, and dies when the session
 * does. The player asks for one, renews it while watching, and gets nothing it
 * could paste into another browser.
 */

export type MediaAssetStatus =
  | "pending"
  | "uploading"
  | "processing"
  | "ready"
  | "failed";

export type MediaAsset = {
  uuid: string;
  status: MediaAssetStatus;
  status_label: string;
  original_filename: string;
  mime_type: string | null;
  size_bytes: number | null;
  duration_seconds: number | null;
  failure_reason: string | null;
  ready_at: string | null;
  created_at: string;
};

export type UploadTicket = {
  url: string;
  method: string;
  headers: Record<string, string>;
  expires_at: string;
};

/** Only ever `progressive` today; `hls` arrives with a provider that produces it. */
export type PlaybackFormat = "progressive" | "hls";

export type PlaybackGrant = {
  grant: string;
  expires_at: string;
  manifest_url: string;
  format: PlaybackFormat;
  watermark: { name: string; phone_masked: string | null };
  renew_after_seconds: number;
  duration_seconds: number | null;
  resume_at_seconds: number;
  renditions: Array<{ label: string; height: number }>;
  captions: Array<{
    uuid: string;
    language: string;
    kind: string;
    is_default: boolean;
  }>;
};

export const media = {
  /** Ask to play a lesson. Refused, not merely unanswered, when not entitled. */
  requestPlayback: (lessonUuid: string) =>
    api.post<PlaybackGrant>(`/lessons/${lessonUuid}/playback`),

  /**
   * Keep a grant alive and record how far the viewer has got.
   *
   * Called on a timer while watching. A 401 or 403 here is how the client learns
   * its session was ended somewhere else — there is no push channel yet.
   */
  renew: (grant: string, positionSeconds: number) =>
    api.post<{ expires_at: string; manifest_url: string }>(
      `/playback/${grant}/renew`,
      { position_seconds: Math.floor(positionSeconds) },
    ),

  requestUpload: (
    lessonUuid: string,
    body: {
      original_filename: string;
      size_bytes?: number;
      duration_seconds?: number;
    },
  ) =>
    api.post<{ asset: MediaAsset; upload: UploadTicket }>(
      `/lessons/${lessonUuid}/assets`,
      body,
    ),

  /**
   * Send the bytes wherever the ticket points.
   *
   * Deliberately ignorant of who is on the other end: the local provider points
   * the ticket back at us, a commercial one points it at its own host, and this
   * function does not change either way.
   */
  uploadTo: async (ticket: UploadTicket, file: File) => {
    const res = await fetch(ticket.url, {
      method: ticket.method,
      headers: ticket.headers,
      body: file,
    });

    if (!res.ok) throw new Error("upload-failed");
  },

  complete: (assetUuid: string) =>
    api.post<MediaAsset>(`/media/assets/${assetUuid}/complete`),

  asset: (assetUuid: string) => api.get<MediaAsset>(`/media/assets/${assetUuid}`),

  remove: (assetUuid: string) => api.delete<void>(`/media/assets/${assetUuid}`),
};
