import { api } from "./api";

/**
 * The student's gamification state, the boards, the shop and the focus timer.
 *
 * ⚠️ COINS ARE PER TEACHER AND THERE IS NO TOTAL. `coin_balances` is a list, and
 * the API sends no sum because no sum is correct: a purse belongs to one teacher,
 * so anything added up here would promise exactly what the shop refuses on the
 * student's first attempt.
 */

export interface CoinPurse {
  workspace_uuid: string | null;
  teacher_name: string;
  coins: number;
}

export interface EarnedBadge {
  key: string;
  name_ar: string;
  icon: string | null;
  awarded_at: string | null;
}

export interface Progress {
  xp: number;
  level: number;
  level_name_ar: string | null;
  /** Null at the top of the ladder — there is no next threshold to show. */
  next_level_xp: number | null;
  current_streak: number;
  best_streak: number;
  shields: number;
  badges: EarnedBadge[];
  coin_balances: CoinPurse[];
}

export interface LeaderboardRow {
  rank: number;
  /** Always the abbreviated form: «خالد ك.». Never a full surname. */
  display_name: string;
  points: number;
  level: number;
}

export interface Leaderboard {
  scope: string;
  period: string;
  level_band: number;
  /** Null while the student has earned nothing in this period. */
  my_rank: number | null;
  my_points: number | null;
  entries: LeaderboardRow[];
}

export interface Reward {
  uuid: string;
  title: string;
  price_coins: number;
  stock: number;
  type: "discount" | "printed" | "deadline_extension" | "streak_shield";
  type_label_ar: string;
  is_active: boolean;
  monthly_cap: number | null;
}

export interface Redemption {
  uuid: string;
  status: "pending" | "fulfilled" | "rejected";
  status_label_ar: string;
  coins_spent: number;
  created_at: string | null;
  decided_at: string | null;
  reward?: { uuid: string; title: string; type: string };
  student_name?: string;
}

export interface FocusSession {
  uuid: string;
  planned_minutes: number;
  started_at: string;
  ended_at: string | null;
  status: "running" | "completed" | "interrupted";
}

export const gamification = {
  me: () => api.get<Progress>("/gamification/me"),

  /**
   * @param scope `platform` · `grade:{slug}` · `subject:{uuid}` · `teacher:{uuid}`
   *              · `course:{uuid}` · `lesson:{uuid}`
   *
   * ⚠️ A scope the reader may not see and one that does not exist both answer
   * 403, deliberately — the difference would be an oracle for what exists. So the
   * screen shows one refusal for both, and must not try to tell them apart.
   */
  leaderboard: (scope: string, period: "week" | "term" = "week") =>
    api.get<Leaderboard>(
      `/gamification/leaderboard?scope=${encodeURIComponent(scope)}&period=${period}`,
    ),

  shop: (workspaceUuid: string) =>
    api.get<{ data: Reward[] }>(`/gamification/shop?workspace=${encodeURIComponent(workspaceUuid)}`),

  redeem: (rewardUuid: string) =>
    api.post<{ data: Redemption }>(`/gamification/rewards/${rewardUuid}/redeem`, {}),

  myRedemptions: () => api.get<{ data: Redemption[] }>("/gamification/redemptions"),

  focus: {
    start: (minutes: number) => api.post<FocusSession>("/gamification/focus", { minutes }),
    end: (uuid: string) => api.post<FocusSession>(`/gamification/focus/${uuid}/end`, {}),
  },

  manage: {
    rewards: () => api.get<{ data: Reward[] }>("/manage/gamification/rewards"),

    saveReward: (payload: Record<string, unknown>, uuid?: string) =>
      uuid
        ? api.put<{ data: Reward }>(`/manage/gamification/rewards/${uuid}`, payload)
        : api.post<{ data: Reward }>("/manage/gamification/rewards", payload),

    redemptions: (status?: string) =>
      api.get<{ data: Redemption[] }>(
        `/manage/gamification/redemptions${status ? `?status=${status}` : ""}`,
      ),

    fulfill: (uuid: string) =>
      api.post<{ data: Redemption }>(`/manage/gamification/redemptions/${uuid}/fulfill`, {}),

    reject: (uuid: string) =>
      api.post<{ data: Redemption }>(`/manage/gamification/redemptions/${uuid}/reject`, {}),
  },
};
