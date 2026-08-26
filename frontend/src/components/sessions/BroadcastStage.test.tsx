import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { BroadcastStage } from "./BroadcastStage";
import type { JoinTicket } from "@/lib/class-sessions";

/*
| ٠١٧ · مشيةُ الهاتف — «‏void promise» ليس معالجةَ خطأ، وقياسُه كان على جهازٍ حقيقيّ.
|
| زرُّ الميكروفون كان `onClick={() => void localParticipant.setMicrophoneEnabled(…)}`.
| والوعدُ المرفوضُ هناك رفضٌ غيرُ ملتقَط: في التطوير تُغطّي طبقةُ الأخطاء الحصّةَ بـ
| `TypeError` خام، **وفي الإنتاج لا يحدث شيءٌ إطلاقاً** — لا رسالة ولا سبب، على الزرِّ
| الوحيدِ الذي يحتاجه الطالبُ ليُرى.
|
| قِيس على هاتفٍ حقيقيّ (‏2026-08-26): النقرُ أجاب
| «‏Cannot read properties of undefined (reading 'getUserMedia')». وحالةُ غيابِ
| `mediaDevices` مُسمّاةٌ وحدَها لأنّ لا جملةَ عامّةً تصفها: خارج السياقِ الآمنِ لا
| يعرضُ المتصفّحُ الواجهةَ أصلاً، فلا شيءَ رُفض ولا نافذةَ إذنٍ ستظهر أبداً.
|
| ⚠️ ولا يراه أيُّ اختبارٍ آخر: الخلفيّةُ لا تعرفُ هذا الزرَّ، وPlaywright يبني للإنتاج
| ويحتاج خادمَين — ولا يستطيعُ أصلاً أن يسلبَ المتصفّحَ `navigator.mediaDevices`.
*/

const setMicrophoneEnabled = vi.fn<(on: boolean) => Promise<void>>();
const setCameraEnabled = vi.fn<(on: boolean) => Promise<void>>();
const setScreenShareEnabled = vi.fn<(on: boolean) => Promise<void>>();

vi.mock("@livekit/components-react", () => ({
  // The room is the library's business; what is under test is our reaction to
  // its verdict, so it renders its children and connects to nothing.
  LiveKitRoom: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  ParticipantTile: () => <div />,
  RoomAudioRenderer: () => <div />,
  useLocalParticipant: () => ({
    localParticipant: { setMicrophoneEnabled, setCameraEnabled, setScreenShareEnabled },
    isMicrophoneEnabled: false,
    isCameraEnabled: false,
    isScreenShareEnabled: false,
  }),
  useParticipantAttributes: () => ({ attributes: {} }),
  // Empty: the participants panel has its own test, and a room with nobody in
  // it is what keeps this file about the three controls it is named after.
  useParticipants: () => [],
  useRemoteParticipants: () => [],
  useTracks: () => [],
}));

vi.mock("@/lib/class-sessions", () => ({
  classSessions: { participants: () => Promise.resolve({ data: [] }) },
}));

vi.mock("livekit-client", () => ({
  Track: { Source: { Camera: "camera", ScreenShare: "screen_share" } },
}));

const TICKET: JoinTicket = {
  room_url: "wss://example.invalid",
  token: "t",
  expires_at: "2026-08-26T12:00:00Z",
  role: "participant",
  presence_interval_seconds: 30,
};

/** Restored by hand: jsdom's navigator has no mediaDevices to begin with. */
const originalMediaDevices = Object.getOwnPropertyDescriptor(navigator, "mediaDevices");

function setMediaDevices(value: unknown): void {
  Object.defineProperty(navigator, "mediaDevices", { value, configurable: true });
}

beforeEach(() => {
  vi.clearAllMocks();
});

afterEach(() => {
  if (originalMediaDevices) {
    Object.defineProperty(navigator, "mediaDevices", originalMediaDevices);
  } else {
    setMediaDevices(undefined);
  }
});

describe("BroadcastStage — self controls", () => {
  it("names the insecure address instead of asking the library for a device that cannot exist", async () => {
    setMediaDevices(undefined);

    render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);
    await userEvent.click(screen.getByRole("button", { name: "تشغيل ميكروفوني" }));

    expect(await screen.findByText(/عنوانٍ آمن/)).toBeTruthy();
    // Not merely a message: the call is never made, because it is the call that
    // throws the raw TypeError the student used to read.
    expect(setMicrophoneEnabled).not.toHaveBeenCalled();
  });

  it("turns a refused camera into an Arabic sentence rather than an unhandled rejection", async () => {
    setMediaDevices({ getUserMedia: vi.fn() });
    setCameraEnabled.mockRejectedValueOnce(new Error("NotAllowedError"));

    render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);
    await userEvent.click(screen.getByRole("button", { name: "تشغيل الكاميرا" }));

    await waitFor(() => {
      expect(setCameraEnabled).toHaveBeenCalledWith(true);
    });
    // Whatever userMessage() renders, the one thing that must not appear is the
    // library's own English.
    const alert = await screen.findByRole("status");
    expect(alert.textContent).not.toContain("NotAllowedError");
  });

  it("asks the library for the screen when the browser can answer", async () => {
    setMediaDevices({ getDisplayMedia: vi.fn() });
    setScreenShareEnabled.mockResolvedValueOnce(undefined);

    render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);
    await userEvent.click(screen.getByRole("button", { name: "مشاركة الشاشة" }));

    await waitFor(() => {
      expect(setScreenShareEnabled).toHaveBeenCalledWith(true);
    });
    expect(screen.queryByRole("status")).toBeNull();
  });
});
