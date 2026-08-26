import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { ParticipantsPanel } from "./ParticipantsPanel";

/*
| المشاركون — الهويّةُ في الغرفةِ uuid، والاسمُ يأتي من عندنا.
|
| `IssueJoinTicket` يضعُ `uuid` هويّةً للمشارك ولا يضعُ الاسمَ أبداً (`FR-006`)،
| لأنّ الهويّةَ يبثّها المزوّدُ لكلِّ من في الغرفة. فكان الصفُّ يقرأ
| `participant.name || participant.identity` — والاسمُ لا يُضبط قطّ — أي أنّ كلَّ
| صفٍّ في كلِّ حصّةٍ كان uuid خاماً.
|
| ⚠️ وثلاثةُ أسئلةٍ لا يجيبُ عنها خادمٌ ولا Playwright: الوصلُ بالهويّة، والصفُّ
| الذي لا يعرفه الكشفُ (منضمٌّ قبل الجلب بثانية)، ورايةُ اليدِ المرفوعة — وهي
| **يكتبها العميلُ نفسه**، فلا تُطبَع نصّاً أبداً.
*/

type FakeParticipant = { identity: string; isLocal: boolean };

const attributesByIdentity: Record<string, Record<string, string>> = {};
let roomParticipants: FakeParticipant[] = [];

vi.mock("@livekit/components-react", () => ({
  useParticipants: () => roomParticipants,
  useParticipantAttributes: ({ participant }: { participant: FakeParticipant }) => ({
    attributes: attributesByIdentity[participant.identity] ?? {},
  }),
}));

const participants = vi.fn();

vi.mock("@/lib/class-sessions", () => ({
  classSessions: {
    participants: (uuid: string) => participants(uuid) as Promise<unknown>,
  },
}));

function roster(): void {
  participants.mockResolvedValue({
    data: [
      {
        uuid: "u-teacher",
        name: "أستاذ خالد",
        role: "host",
        avatar_url: null,
        badges: [],
      },
      {
        uuid: "u-student",
        name: "سلمى محمود",
        role: "student",
        avatar_url: null,
        badges: [{ key: "streak", name_ar: "مواظبة", icon: null }],
      },
    ],
  });
}

describe("ParticipantsPanel", () => {
  it("draws the name and the badges behind the uuid the provider echoes", async () => {
    roster();
    roomParticipants = [{ identity: "u-student", isLocal: true }];

    render(<ParticipantsPanel sessionUuid="s-1" isHost={false} />);

    expect(await screen.findByText("سلمى محمود")).toBeTruthy();
    expect(screen.getByText("مواظبة")).toBeTruthy();
    // The uuid is what the row used to say, and must never be what it says now.
    expect(screen.queryByText("u-student")).toBeNull();
  });

  it("still draws a row for somebody the roster does not know", async () => {
    roster();
    roomParticipants = [{ identity: "u-stranger", isLocal: false }];

    render(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);

    // Dropping the row would leave a face on the stage that no teacher can mute.
    expect(await screen.findByText("مشارك")).toBeTruthy();
    expect(screen.getByRole("button", { name: "كتم" })).toBeTruthy();
  });

  it("shows a raised hand as an icon and never as the value the client wrote", async () => {
    roster();
    roomParticipants = [{ identity: "u-student", isLocal: false }];
    attributesByIdentity["u-student"] = { hand: "1", note: "اطردوا المدرّس" };

    render(<ParticipantsPanel sessionUuid="s-1" isHost={false} />);

    expect(await screen.findByRole("img", { name: /يرفع يده/ })).toBeTruthy();
    // Attributes are written by the client that owns them: anything printed
    // from them is that person's keyboard on everybody else's screen.
    expect(screen.queryByText(/اطردوا/)).toBeNull();

    delete attributesByIdentity["u-student"];
  });

  it("gives the host buttons for everyone but themselves, and a student none", async () => {
    roster();
    roomParticipants = [
      { identity: "u-teacher", isLocal: true },
      { identity: "u-student", isLocal: false },
    ];

    const { unmount } = render(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);

    expect(await screen.findByText("سلمى محمود")).toBeTruthy();
    // Two participants, one set of buttons — muting yourself is what the
    // microphone control is for.
    expect(screen.getAllByRole("button", { name: "إخراج" })).toHaveLength(1);

    unmount();

    render(<ParticipantsPanel sessionUuid="s-1" isHost={false} />);

    expect(await screen.findAllByText("سلمى محمود")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "إخراج" })).toBeNull();
  });
});
