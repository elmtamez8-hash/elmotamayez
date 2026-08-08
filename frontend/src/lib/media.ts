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

/** What the file IS. Decides its mime list, its size ceiling and its editor. */
export type MediaKind = "video" | "audio" | "document";

/**
 * `primary` is the item's own file — one per item, replaced on re-upload.
 * `attachment` sits beside it, and there may be many, on an item of any type.
 */
export type MediaRole = "primary" | "attachment";

export type MediaAsset = {
  uuid: string;
  status: MediaAssetStatus;
  status_label: string;
  kind: MediaKind;
  kind_label: string;
  role: MediaRole;
  /**
   * View-only versus allow-download. The server reads this on every range
   * request and sets `Content-Disposition` from it — this field is what the
   * teacher's switch reflects, not what enforces it.
   */
  is_downloadable: boolean;
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
  captions: Caption[];
};

/**
 * `url` runs through the grant, exactly as the video does — so the lesson's
 * script stops being readable at the same moment the video stops playing.
 */
export type Caption = {
  uuid: string;
  language: string;
  kind: string;
  is_default: boolean;
  url: string;
};

export const media = {
  /** Ask to play a lesson. Refused, not merely unanswered, when not entitled. */
  requestPlayback: (lessonUuid: string) =>
    api.post<PlaybackGrant>(`/lessons/${lessonUuid}/playback`),

  /**
   * Ask for one named file on the item — an attachment, or its own file by uuid.
   *
   * Entitlement is the lesson's, whichever file is asked for: an attachment
   * travels with the item it hangs on. There is no permanent path to one, which
   * is why this exists at all.
   */
  requestAssetPlayback: (lessonUuid: string, assetUuid: string) =>
    api.post<PlaybackGrant>(`/lessons/${lessonUuid}/assets/${assetUuid}/playback`),

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
      /** Defaults to video/primary on the server, for the page that predates 016. */
      kind?: MediaKind;
      role?: MediaRole;
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

  /**
   * Flip view-only / allow-download on a file already uploaded.
   *
   * Separate from the upload because the teacher changes their mind later, and
   * making them re-upload to change one boolean is how a switch stops being used.
   */
  setDisposition: (assetUuid: string, isDownloadable: boolean) =>
    api.put<MediaAsset>(`/media/assets/${assetUuid}/disposition`, {
      is_downloadable: isDownloadable,
    }),

  remove: (assetUuid: string) => api.delete<void>(`/media/assets/${assetUuid}`),

  /**
   * Attach a WebVTT track. Re-uploading the same language replaces it rather
   * than adding a second one — correcting a typo is the ordinary case.
   */
  attachCaption: (assetUuid: string, file: File, language = "ar") => {
    const form = new FormData();
    form.append("file", file);
    form.append("language", language);

    return api.upload<Caption>(`/media/assets/${assetUuid}/captions`, form);
  },

  removeCaption: (captionUuid: string) =>
    api.delete<void>(`/media/captions/${captionUuid}`),
};
