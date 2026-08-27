import { api } from "./api";

/**
 * Notification types and channels as the API describes them.
 *
 * Arabic labels come from the server, not a table here: the type list grows with
 * every later phase, and a second copy of the wording would be stale the day 005
 * adds attendance alerts.
 */

export type NotificationChannel = {
  key: string;
  label: string;
};

export type NotificationTypeMeta = {
  key: string;
  label: string;
  default_channels: string[];
  is_mandatory: boolean;
  targets_guardians: boolean;
};

export type NotificationItem = {
  uuid: string;
  type: string;
  type_label: string;
  /**
   * The SUBJECT this row belongs to, named by the server.
   *
   * ⚠️ NOT DERIVED HERE. The map from forty-eight types to seven subjects lives
   * on the server; a copy in the browser goes stale the day a type is re-filed,
   * silently, because a row with the wrong icon still renders. Null for a type
   * nobody has classified — it still arrives and still reads, it just belongs to
   * no tab.
   */
  category: { key: string; label: string } | null;
  title: string;
  body: string;
  action_url: string | null;
  subject: { uuid: string; name: string } | null;
  workspace: { uuid: string; name: string } | null;
  read_at: string | null;
  created_at: string | null;
};

/**
 * One tab of the notification centre.
 *
 * ⚠️ THE SERVER DECIDES WHICH SUBJECTS EXIST FOR THIS READER. A student receives
 * no settlement notice and a teacher no guardian-consent request, so a fixed row
 * of seven tabs would show each of them a control that empties the page. And the
 * COUNT is the point rather than the hiding: without it the tabs are seven
 * guesses, with it the page says where the unread sixty-four actually are before
 * the reader presses anything.
 */
export type NotificationCategory = {
  key: string;
  label: string;
  unread: number;
  total: number;
};

export type NotificationPage = {
  data: NotificationItem[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
    unread_count: number;
    /** Describes the WHOLE feed, not the tab being read. */
    categories: NotificationCategory[];
  };
};

export type NotificationPreference = {
  type: string;
  channels: string[];
  digest_window_minutes: number | null;
};

export type GuardianRelation = {
  uuid: string;
  relation_type: "parent" | "guardian";
  relation_type_label: string;
  status: "pending" | "active" | "revoked";
  status_label: string;
  student_name: string;
  student_age: number | null;
  student_grade_level_slug: string | null;
  student_has_account: boolean;
  /**
   * Present ONLY on a row the reader is the guardian of — a teacher reading a
   * student's guardians, or a student reading their own, never sees it. It is
   * what every child-scoped screen sends as `?student=`.
   */
  student_uuid?: string;
  guardian?: { uuid: string; name: string };
  permissions: { key: string; label: string }[];
  revoked_at: string | null;
  created_at: string | null;
};

/** The event every unread-count reader listens for. */
export const NOTIFICATIONS_CHANGED = "notifications:changed";

function announceRead<T>(result: T): T {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent(NOTIFICATIONS_CHANGED));
  }

  return result;
}

export const notifications = {
  list: (
    params: { page?: number; unread?: boolean; workspace?: string; category?: string } = {},
  ) => {
    const query = new URLSearchParams();
    if (params.page) query.set("page", String(params.page));
    if (params.unread) query.set("unread", "1");
    if (params.workspace) query.set("workspace", params.workspace);
    // A SUBJECT, not a type. Forty-eight types is a second list to read; the
    // reader wants «which of these is about my money and which about my
    // lessons». An unknown value empties the feed rather than widening it.
    if (params.category) query.set("category", params.category);

    const suffix = query.toString();
    return api.get<NotificationPage>(`/notifications${suffix ? `?${suffix}` : ""}`);
  },
  unreadCount: () => api.get<{ unread_count: number }>("/notifications/unread-count"),

  /*
   * ⚠️ THE ANNOUNCEMENT LIVES HERE, NOT IN THE SCREEN THAT PRESSED THE BUTTON.
   * The notifications page kept its own `unread` and the header bell kept its
   * own `count`, and neither told the other — so marking everything read left the
   * badge showing the old number until the sixty-second poll came round or the
   * reader pressed refresh. Announcing it from the request layer means every
   * caller is covered, including the ones written after this line.
   *
   * ⚠️ AND IT ANNOUNCES «GO AND ASK» RATHER THAN CARRYING THE NUMBER. The
   * response holds a fresh count, but a listener that trusted it would drift the
   * moment two tabs are open — the server is the source, exactly as it is for the
   * chat, and the socket and this event are both only ways of learning to look.
   */
  markRead: (uuid: string) =>
    api.post<{ unread_count: number }>(`/notifications/${uuid}/read`).then(announceRead),
  markAllRead: () =>
    api.post<{ unread_count: number }>("/notifications/read-all").then(announceRead),

  types: () =>
    api.get<{ channels: NotificationChannel[]; types: NotificationTypeMeta[] }>(
      "/notifications/types",
    ),
  preferences: () => api.get<{ preferences: NotificationPreference[] }>("/notifications/preferences"),
  updatePreferences: (preferences: NotificationPreference[]) =>
    api.put<{ preferences: NotificationPreference[] }>("/notifications/preferences", {
      preferences,
    }),
  updateQuietHours: (data: {
    quiet_hours_start: string | null;
    quiet_hours_end: string | null;
    timezone: string | null;
  }) => api.put<typeof data>("/notifications/quiet-hours", data),
};

/**
 * Proving a contact detail belongs to this account (spec 020).
 *
 * The code is never in either response: it travels over the channel being
 * verified, which is the entire point — a channel that echoes it back over HTTP
 * has verified nothing.
 */
export const contactVerification = {
  request: (channel: string, contactValue: string) =>
    api.post<{ uuid: string; expires_at: string | null }>("/contact-verifications", {
      channel,
      contact_value: contactValue,
    }),
  confirm: (uuid: string, code: string) =>
    api.post<{ uuid: string; channel: string; verified_at: string | null }>(
      `/contact-verifications/${uuid}/confirm`,
      { code },
    ),
};

export const family = {
  list: () => api.get<{ data: GuardianRelation[] }>("/family/relations"),
  add: (data: {
    student_name: string;
    age?: number | null;
    grade_level_slug?: string | null;
    student_uuid?: string | null;
    relation_type: "parent" | "guardian";
    permissions: string[];
  }) => api.post<GuardianRelation>("/family/relations", data),
  updatePermissions: (uuid: string, permissions: string[]) =>
    api.patch<GuardianRelation>(`/family/relations/${uuid}`, { permissions }),
  revoke: (uuid: string) => api.delete<GuardianRelation>(`/family/relations/${uuid}`),
};

/** The five permissions a guardian relation can carry, matching the backend enum. */
export const GUARDIAN_PERMISSIONS: { key: string; label: string }[] = [
  { key: "attendance", label: "الحضور والغياب" },
  { key: "payments", label: "المدفوعات والمستحقّات" },
  { key: "schedule", label: "المواعيد والحصص" },
  { key: "results", label: "النتائج والدرجات" },
  { key: "academic_warnings", label: "الإنذارات الأكاديمية" },
  /*
   * ⚠️ THE SIXTH, ADDED BY SPEC 013 — and without it a guardian could not consent
   * to the processing of their own child's data unless they had also been granted
   * authority over the MONEY. `RecordTermsConsent` asked for `payments` because it
   * was the closest value that existed, which made 013's own rule ("an authorised
   * guardian grants, an unauthorised one does not") impossible to state: there was
   * nothing to be authorised FOR.
   *
   * It is also what a data-rights request is checked against — deliberately NOT
   * `relations.view.student`, which every teacher and assistant holds and which
   * would be a cross-workspace export of a child's entire record.
   */
  { key: "data_rights", label: "الموافقة على معالجة البيانات وطلب حقوقها" },
];
