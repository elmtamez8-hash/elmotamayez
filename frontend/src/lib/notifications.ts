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
  title: string;
  body: string;
  action_url: string | null;
  subject: { uuid: string; name: string } | null;
  workspace: { uuid: string; name: string } | null;
  read_at: string | null;
  created_at: string | null;
};

export type NotificationPage = {
  data: NotificationItem[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
    unread_count: number;
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
  guardian?: { uuid: string; name: string };
  permissions: { key: string; label: string }[];
  revoked_at: string | null;
  created_at: string | null;
};

export const notifications = {
  list: (params: { page?: number; unread?: boolean; workspace?: string } = {}) => {
    const query = new URLSearchParams();
    if (params.page) query.set("page", String(params.page));
    if (params.unread) query.set("unread", "1");
    if (params.workspace) query.set("workspace", params.workspace);

    const suffix = query.toString();
    return api.get<NotificationPage>(`/notifications${suffix ? `?${suffix}` : ""}`);
  },
  unreadCount: () => api.get<{ unread_count: number }>("/notifications/unread-count"),
  markRead: (uuid: string) => api.post<{ unread_count: number }>(`/notifications/${uuid}/read`),
  markAllRead: () => api.post<{ unread_count: number }>("/notifications/read-all"),

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
];
