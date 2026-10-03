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
const setScreenShareEnabled = vi.fn<(on: boolean, capture?: object, publish?: object) => Promise<void>>();

/**
 * The local participant's permissions as the provider pushed them. Reset to
 * «may publish everything» before each test; the permission tests narrow it.
 * The numbers are the protocol's `TrackSource` (1 camera · 2 microphone ·
 * 3 screen · 4 screen audio).
 */
const ALL_SOURCES = { canPublish: true, canPublishSources: [1, 2, 3, 4] };
let permissions: { canPublish: boolean; canPublishSources: number[] } | undefined = ALL_SOURCES;
let attributes: Record<string, string> = {};

/** What the component asked the library to do on connect. */
const roomProps = vi.fn<(props: { audio?: boolean; video?: boolean }) => void>();

vi.mock("@livekit/components-react", () => ({
  // The room is the library's business; what is under test is our reaction to
  // its verdict, so it renders its children and connects to nothing. Its props
  // are recorded, because `audio`/`video` are a decision of ours and not the
  // library's — see the publish-on-connect tests below.
  LiveKitRoom: ({
    children,
    ...props
  }: {
    children: React.ReactNode;
    audio?: boolean;
    video?: boolean;
  }) => {
    roomProps(props);

    return <div>{children}</div>;
  },
  ParticipantTile: () => <div />,
  RoomAudioRenderer: () => <div />,
  useLocalParticipant: () => ({
    localParticipant: { setMicrophoneEnabled, setCameraEnabled, setScreenShareEnabled },
    isMicrophoneEnabled: false,
    isCameraEnabled: false,
    isScreenShareEnabled: false,
  }),
  useParticipantAttributes: () => ({ attributes }),
  useLocalParticipantPermissions: () => permissions,
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
  ScreenSharePresets: {
    h1080fps15: { resolution: { width: 1920, height: 1080, frameRate: 15 }, encoding: { maxBitrate: 2_500_000, maxFramerate: 15 } },
    h720fps5: { width: 1280, height: 720 },
  },
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
  permissions = ALL_SOURCES;
  attributes = {};
});

afterEach(() => {
  if (originalMediaDevices) {
    Object.defineProperty(navigator, "mediaDevices", originalMediaDevices);
  } else {
    setMediaDevices(undefined);
  }
});

describe("BroadcastStage — who publishes on connect", () => {
  /*
  | ⚠️ الطالبةُ تدخلُ والكاميرا مطفأة، وكانت `audio video` عاريتَين.
  |
  | المكتبةُ تقرأ الاثنتين على أنّهما «انشُر فوراً بعد الاتصال»، فبنتُ الرابعةَ عشرةَ
  | تفتحُ الدرسَ من هاتفها فتظهرُ غرفتُها على المسرح — وتدخلُ ملفَّ التسجيل — قبل أن
  | تلمسَ شيئاً. و`RecordingNotice` مرسومٌ فوقَ هذا مباشرةً يقول «إن لم ترغب في
  | الظهور، أغلِقِ الكاميرا»: إنذارٌ بعد وقوعِ الفعل، وهو بالضبط ما يرفضه توثيقُ ذلك
  | المكوّنِ نفسِه.
  |
  | والمدرّسُ استثناءٌ مقصود: زرّان قبل أن يسمعَه الفصلُ هما أوّلُ ثلاثين ثانيةٍ من كلِّ
  | حصّةٍ تضيعُ في السباكة. والدورُ من التذكرةِ المُوقَّعة، فلا يُقلَبُ من المتصفّح.
  */
  it("leaves a student's camera and microphone off until they ask", () => {
    render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);

    expect(roomProps).toHaveBeenCalledWith(
      expect.objectContaining({ audio: false, video: false }),
    );
  });

  it("lets the teacher arrive already publishing", () => {
    render(<BroadcastStage ticket={{ ...TICKET, role: "host" }} sessionUuid="s-1" />);

    expect(roomProps).toHaveBeenCalledWith(expect.objectContaining({ audio: true, video: true }));
  });
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
      // Sent for legibility (T111): a board, not a film.
      expect(setScreenShareEnabled).toHaveBeenCalledWith(
        true,
        expect.objectContaining({ contentHint: "detail", video: { displaySurface: "browser" } }),
        expect.objectContaining({ screenShareSimulcastLayers: [{ width: 1280, height: 720 }] }),
      );
    });
    expect(screen.queryByRole("status")).toBeNull();
  });
});

describe("BroadcastStage — what the teacher and a student may do (2026-09-30)", () => {
  /*
  | ⚠️ «ارفع يدك» و«لم أفهم» طلبانِ من الطالبِ إلى من يُدرِّس — المدرّسُ الذي يرفعُ
  | يدَه في حصّتِه يضعُ ✋ في الطابورِ الذي يُفترَضُ أن يقرأه.
  */
  it("gives the host no hand to raise and no «لم أفهم»", () => {
    render(<BroadcastStage ticket={{ ...TICKET, role: "host" }} sessionUuid="s-1" />);

    expect(screen.queryByRole("button", { name: /ارفع يدك/ })).toBeNull();
    expect(screen.queryByRole("button", { name: /لم أفهم/ })).toBeNull();
    // The host's own media controls are all there.
    expect(screen.getByRole("button", { name: "تشغيل ميكروفوني" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "مشاركة الشاشة" })).toBeTruthy();
  });

  it("gives a student both signals", () => {
    render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);

    expect(screen.getByRole("button", { name: /ارفع يدك/ })).toBeTruthy();
    expect(screen.getByRole("button", { name: /لم أفهم/ })).toBeTruthy();
  });

  /*
  | الكتمُ صلاحيّةٌ يدفعُها المزوّدُ إلى المتصفّح أثناءَ الحصّة، فالزرُّ يتبعُها حيّةً —
  | بلا تحديث. زرٌّ يقرأ علماً محلّياً يعرضُ ميكروفوناً رفضَه الخادمُ.
  */
  it("disables her microphone and says why the moment the teacher takes it", () => {
    const { rerender } = render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);

    const mic = screen.getByRole("button", { name: "تشغيل ميكروفوني" }) as HTMLButtonElement;
    expect(mic.disabled).toBe(false);
    expect(screen.queryByText(/كتمك المدرّس/)).toBeNull();

    // The provider pushes a permission set with the camera alone.
    permissions = { canPublish: true, canPublishSources: [1] };
    rerender(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);

    expect((screen.getByRole("button", { name: "تشغيل ميكروفوني" }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText("كتمك المدرّس — ارفع يدك لتطلب الكلام.")).toBeTruthy();
    // And the hand is exactly the way back, so it stays.
    expect(screen.getByRole("button", { name: /ارفع يدك/ })).toBeTruthy();
  });

  it("hides the screen-share button from a student until the teacher allows it", () => {
    permissions = { canPublish: true, canPublishSources: [1, 2] };
    const { rerender } = render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);

    expect(screen.queryByRole("button", { name: "مشاركة الشاشة" })).toBeNull();

    permissions = { canPublish: true, canPublishSources: [1, 2, 3, 4] };
    rerender(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);

    expect(screen.getByRole("button", { name: "مشاركة الشاشة" })).toBeTruthy();
  });

  it("keeps a student's screen closed before the room has told her anything", () => {
    permissions = undefined;
    render(<BroadcastStage ticket={TICKET} sessionUuid="s-1" />);

    expect(screen.queryByRole("button", { name: "مشاركة الشاشة" })).toBeNull();
    // Nothing was refused yet, so the microphone is not drawn as taken.
    expect(screen.queryByText(/كتمك المدرّس/)).toBeNull();
  });
});
