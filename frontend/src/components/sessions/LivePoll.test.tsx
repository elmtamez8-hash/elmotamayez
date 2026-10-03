import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { decodePoll, encodePoll, type Poll } from "@/lib/live-poll";

const room = vi.hoisted(() => {
  const handlers = new Map<string, Set<(...args: unknown[]) => void>>();
  const r = {
    handlers,
    remoteParticipants: new Map<string, { identity: string; attributes: Record<string, string> }>(),
    localParticipant: {
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
    expect(screen.getByText(/صوّت 2/)).toBeTruthy();

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

    act(() => room.emit("connected", { identity: "late" }));

    await waitFor(() => expect(room.localParticipant.publishData).toHaveBeenCalledTimes(2));
    expect(room.localParticipant.publishData.mock.calls[1][1]).toMatchObject({ destinationIdentities: ["late"], reliable: true });
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
    expect(room.localParticipant.setAttributes).toHaveBeenCalledWith({ hand: "1", poll: "abcd1234:1" });

    arrive({ ...poll, state: "closed", counts: [1, 3] });
    expect(await screen.findByText("3 · 75٪")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "٤" })).toBeNull();

    arrive({ ...poll, state: "ended" });
    expect(screen.queryByText("ناتج ٢+٢؟")).toBeNull();
  });
});

