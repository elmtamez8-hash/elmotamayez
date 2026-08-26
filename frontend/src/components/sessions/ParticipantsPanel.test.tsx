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

let speaking = "";

vi.mock("@livekit/components-react", () => ({
  useParticipants: () => roomParticipants,
  useParticipantAttributes: ({ participant }: { participant: FakeParticipant }) => ({
    attributes: attributesByIdentity[participant.identity] ?? {},
  }),
  useIsSpeaking: (participant: FakeParticipant) => participant.identity === speaking,
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

  it("offers the host a way back for whoever they put out", async () => {
    /*
     * ⚠️ THE ONE PLACE THIS PANEL DRAWS SOMEBODY WHO IS NOT CONNECTED. A removed
     * student is by definition out of the room, so without this list the teacher
     * has nobody to press «اسمح بالعودة» on — and since the removal now outlives
     * the disconnect, a press in error would keep a paying student out of the
     * lesson for the rest of the hour.
     */
    participants.mockResolvedValue({
      data: [
        {
          uuid: "u-out",
          name: "طارق سعيد",
          role: "student",
          avatar_url: null,
          badges: [],
          is_removed: true,
        },
      ],
    });
    roomParticipants = [];

    render(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);

    expect(await screen.findByText("طارق سعيد")).toBeTruthy();
    expect(screen.getByRole("button", { name: "اسمح بالعودة" })).toBeTruthy();
  });

  it("shows a classmate nothing about who was put out", async () => {
    // The server does not send the key to anyone but the host; this asserts the
    // screen would not draw it even if it arrived.
    participants.mockResolvedValue({
      data: [
        { uuid: "u-out", name: "طارق سعيد", role: "student", avatar_url: null, badges: [] },
      ],
    });
    roomParticipants = [];

    const { container } = render(<ParticipantsPanel sessionUuid="s-1" isHost={false} />);

    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(container.textContent).not.toContain("أُخرجوا");
    expect(screen.queryByRole("button", { name: "اسمح بالعودة" })).toBeNull();
  });

  it("numbers raised hands in the order this screen saw them", async () => {
    /*
     * ⚠️ THE ORDER IS OBSERVED, NOT SENT. A timestamp inside the attribute is
     * the obvious design and the wrong one: attributes are written by the client
     * that owns them, so the student who wants to be first would simply say they
     * were — and being asked in turn is the whole point.
     *
     * ⚠️ AND THE SEQUENCE BELOW IS WHAT MAKES THIS TEST MEAN ANYTHING. The
     * student raises FIRST, then lowers and raises again — so whoever sorts by
     * anything stable about the row (the order the rows render, the roster, the
     * identity) puts them back at the front, which is precisely the queue-jump
     * this feature exists to prevent. Written the obvious way — two people, two
     * raises — it passed against a build with no ordering in it at all.
     */
    roster();
    roomParticipants = [
      { identity: "u-teacher", isLocal: true },
      { identity: "u-student", isLocal: false },
    ];
    attributesByIdentity["u-student"] = { hand: "1" };

    const { rerender } = render(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);
    expect(await screen.findByRole("img", { name: /سلمى محمود يرفع يده — الدور 1/ })).toBeTruthy();

    attributesByIdentity["u-teacher"] = { hand: "1" };
    rerender(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);
    expect(await screen.findByRole("img", { name: /أستاذ خالد يرفع يده — الدور 2/ })).toBeTruthy();

    // Down, then up again: the student goes to the back of the queue.
    delete attributesByIdentity["u-student"];
    rerender(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);
    attributesByIdentity["u-student"] = { hand: "1" };
    rerender(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);

    expect(await screen.findByRole("img", { name: /أستاذ خالد يرفع يده — الدور 1/ })).toBeTruthy();
    expect(screen.getByRole("img", { name: /سلمى محمود يرفع يده — الدور 2/ })).toBeTruthy();

    delete attributesByIdentity["u-student"];
    delete attributesByIdentity["u-teacher"];
  });

  it("counts «لم أفهم» without naming anybody in the header", async () => {
    // A count is what a teacher mid-explanation can act on; the names are one
    // glance down the list, where the icon sits beside the row.
    roster();
    roomParticipants = [{ identity: "u-student", isLocal: false }];
    attributesByIdentity["u-student"] = { confused: "1" };

    render(<ParticipantsPanel sessionUuid="s-1" isHost={true} />);

    expect(await screen.findByText(/لم يفهموا/)).toBeTruthy();
    expect(screen.getByRole("img", { name: /سلمى محمود لم يفهم/ })).toBeTruthy();

    delete attributesByIdentity["u-student"];
  });

  it("marks who is speaking, and only them", async () => {
    roster();
    roomParticipants = [
      { identity: "u-teacher", isLocal: true },
      { identity: "u-student", isLocal: false },
    ];
    speaking = "u-student";

    render(<ParticipantsPanel sessionUuid="s-1" isHost={false} />);

    expect(await screen.findByRole("img", { name: "سلمى محمود يتحدّث الآن" })).toBeTruthy();
    expect(screen.queryByRole("img", { name: "أستاذ خالد يتحدّث الآن" })).toBeNull();

    speaking = "";
  });
});
