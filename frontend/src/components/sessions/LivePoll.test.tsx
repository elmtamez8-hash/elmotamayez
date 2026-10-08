import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { decodePoll, encodePoll, type Poll } from "@/lib/live-poll";

const room = vi.hoisted(() => {
  const handlers = new Map<string, Set<(...args: unknown[]) => void>>();
  const r = {
    handlers,
    remoteParticipants: new Map<string, { identity: string; attributes: Record<string, string> }>(),
    localParticipant: {
      identity: "me",
      attributes: {} as Record<string, string>,
      publishData: vi.fn(async (_payload: Uint8Array, _options: unknown) => undefined),
      setAttributes: vi.fn(async (_attributes: Record<string, string>) => undefined),
    },
    on(event: string, fn: (...args: unknown[]) => void) {
      if (!handlers.has(event)) handlers.set(event, new Set());
      handlers.get(event)!.add(fn);
      return r;
    },
    off(event: string, fn: (...args: unknown[]) => void) {
      handlers.get(event)?.delete(fn);
      return r;
    },
    emit(event: string, ...args: unknown[]) {
      handlers.get(event)?.forEach((fn) => fn(...args));
    },
  };
  return r;
});

vi.mock("@livekit/components-react", () => ({
  useRoomContext: () => room,
  useLocalParticipant: () => ({ localParticipant: room.localParticipant }),
  useParticipantAttributes: () => ({ attributes: room.localParticipant.attributes }),
}));
vi.mock("livekit-client", () => ({
  RoomEvent: {
    DataReceived: "dataReceived",
    ParticipantAttributesChanged: "attributesChanged",
    ParticipantConnected: "connected",
    ParticipantDisconnected: "disconnected",
    ParticipantActive: "active",
    Connected: "roomConnected",
  },
}));
vi.mock("@/lib/class-sessions", () => ({
  classSessions: {
    participants: async () => ({ data: [{ uuid: "s1", name: "منى" }, { uuid: "s2", name: "عمر" }] }),
  },
}));

import { LivePoll } from "./LivePoll";

const sent = (call: number) => decodePoll(room.localParticipant.publishData.mock.calls[call][0])!;

beforeEach(() => {
  vi.clearAllMocks();
  room.handlers.clear();
  room.remoteParticipants.clear();
  room.localParticipant.attributes = {};
  sessionStorage.clear();
});

describe("LivePoll — the teacher", () => {
  it("asks, sees who chose what, and announces the count", async () => {
    render(<LivePoll sessionUuid="sess" isHost />);
    fireEvent.click(screen.getByRole("button", { name: /تصويت سريع/ }));
    fireEvent.click(screen.getByRole("button", { name: "صح / غلط" }));
    fireEvent.click(screen.getByRole("button", { name: "ابدأ التصويت" }));

    await waitFor(() => expect(room.localParticipant.publishData).toHaveBeenCalledTimes(1));
    const poll = sent(0);
    expect(poll).toMatchObject({ options: ["صح", "غلط"], state: "open" });
    expect(poll.counts).toBeUndefined(); // nothing about the votes while it is open

    room.remoteParticipants.set("s1", { identity: "s1", attributes: { poll: `${poll.id}:0` } });
    room.remoteParticipants.set("s2", { identity: "s2", attributes: { poll: `${poll.id}:0` } });
    room.remoteParticipants.set("s3", { identity: "s3", attributes: { poll: "stale000:1" } });
    act(() => room.emit("attributesChanged"));

    expect(await screen.findByText("منى، عمر")).toBeTruthy();
    expect(screen.getByText(/صوّت ٢/)).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "اقفل التصويت وأعلن النتيجة" }));
    await waitFor(() => expect(room.localParticipant.publishData).toHaveBeenCalledTimes(2));
    expect(sent(1)).toMatchObject({ id: poll.id, state: "closed", counts: [2, 0] });
  });

  it("sends the open poll to whoever arrives late", async () => {
    render(<LivePoll sessionUuid="sess" isHost />);
    fireEvent.click(screen.getByRole("button", { name: /تصويت سريع/ }));
    fireEvent.click(screen.getByRole("button", { name: "فهمت؟" }));
    fireEvent.click(screen.getByRole("button", { name: "ابدأ التصويت" }));
    await screen.findByText(/التصويت مفتوح/);
    // The greeting subscribes in an effect after that render; an arrival
    // emitted before it lands on nobody (flaked on CI).
    await waitFor(() => expect(room.handlers.get("active")?.size).toBe(1));

    act(() => room.emit("active", { identity: "late" }));

    await waitFor(() => expect(room.localParticipant.publishData).toHaveBeenCalledTimes(2));
    expect(room.localParticipant.publishData.mock.calls[1][1]).toMatchObject({ destinationIdentities: ["late"], reliable: true });
  });
});

describe("LivePoll — a teacher who reloads, and a second host", () => {
  const poll: Poll = { v: 1, id: "abcd1234", question: "", options: ["أ", "ب"], state: "open" };

  it("reads the votes already cast once the room connects, and tells the room again", async () => {
    sessionStorage.setItem("live-poll:sess", JSON.stringify(poll));
    render(<LivePoll sessionUuid="sess" isHost />);
    expect(await screen.findByText(/صوّت ٠/)).toBeTruthy();

    // The seats came with the join answer: no ParticipantConnected, only Connected.
    room.remoteParticipants.set("s1", { identity: "s1", attributes: { poll: "abcd1234:1" } });
    act(() => room.emit("roomConnected"));

    expect(await screen.findByText(/صوّت ١/)).toBeTruthy();
    await waitFor(() => expect(room.localParticipant.publishData).toHaveBeenCalledTimes(1));
    expect(room.localParticipant.publishData.mock.calls[0][1]).toMatchObject({ destinationIdentities: undefined });
  });

  it("takes up another host's poll instead of running a second one", async () => {
    render(<LivePoll sessionUuid="sess" isHost />);
    act(() => room.emit("dataReceived", encodePoll({ ...poll, question: "سؤال المساعد" }), undefined, undefined, "poll"));
    expect(await screen.findByText("سؤال المساعد")).toBeTruthy();
  });
});

describe("LivePoll — the student", () => {
  const poll: Poll = { v: 1, id: "abcd1234", question: "ناتج ٢+٢؟", options: ["٣", "٤"], state: "open" };
  const arrive = (p: Poll, topic = "poll") => act(() => room.emit("dataReceived", encodePoll(p), undefined, undefined, topic));

  it("answers with their own attribute, then sees the result once the teacher closes it", async () => {
    room.localParticipant.attributes = { hand: "1" };
    render(<LivePoll sessionUuid="sess" isHost={false} />);
    arrive(poll, "chat");
    expect(screen.queryByText("ناتج ٢+٢؟")).toBeNull();

    arrive(poll);
    fireEvent.click(await screen.findByRole("button", { name: "٤" }));
    expect(room.localParticipant.setAttributes).toHaveBeenCalledWith({ poll: "abcd1234:1" });
    // Chosen is announced, not only coloured (the shared Button dropped `aria-pressed`).
    room.localParticipant.attributes = { hand: "1", poll: "abcd1234:1" };
    arrive({ ...poll });
    expect((await screen.findByRole("button", { name: "٤" })).getAttribute("aria-pressed")).toBe("true");
    expect(screen.getByRole("button", { name: "٣" }).getAttribute("aria-pressed")).toBe("false");

    arrive({ ...poll, state: "closed", counts: [1, 3] });
    expect(await screen.findByText("٣ · ٧٥٪")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "٤" })).toBeNull();

    arrive({ ...poll, state: "ended" });
    expect(screen.queryByText("ناتج ٢+٢؟")).toBeNull();
  });

  it("says its vote again after a reload: the new connection has none, and the teacher's count lost it", async () => {
    const { unmount } = render(<LivePoll sessionUuid="sess" isHost={false} />);
    arrive(poll);
    room.localParticipant.setAttributes.mockImplementationOnce(() => new Promise(() => {})); // reloaded before the answer came back
    fireEvent.click(await screen.findByRole("button", { name: "٤" }));
    expect(sessionStorage.getItem("live-poll-vote:me")).toBe("abcd1234:1");
    unmount();

    // The reload: a fresh connection with no attribute, and the teacher re-sends the poll.
    vi.clearAllMocks();
    room.handlers.clear();
    room.localParticipant.attributes = {};
    const { unmount: unmount2 } = render(<LivePoll sessionUuid="sess" isHost={false} />);
    arrive(poll);
    await waitFor(() => expect(room.localParticipant.setAttributes).toHaveBeenCalledWith({ poll: "abcd1234:1" }));

    // Another student signed into this tab says nothing in her name.
    unmount2();
    vi.clearAllMocks();
    room.handlers.clear();
    room.localParticipant.identity = "someone-else";
    render(<LivePoll sessionUuid="sess" isHost={false} />);
    arrive(poll);
    await screen.findByRole("button", { name: "٤" });
    expect(room.localParticipant.setAttributes).not.toHaveBeenCalled();
    room.localParticipant.identity = "me";

    // A later poll is a new question: nothing is said for it.
    vi.clearAllMocks();
    arrive({ ...poll, id: "efgh5678" });
    await screen.findByRole("button", { name: "٤" });
    expect(room.localParticipant.setAttributes).not.toHaveBeenCalled();
  });
});

