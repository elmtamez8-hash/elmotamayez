import { Avatar } from "@/components/ui/Avatar";

/**
 * A face, with a green dot when the person has this thread open right now.
 *
 * ⚠️ THE DOT MEANS «IN THIS CONVERSATION NOW», NOT «ONLINE SOMEWHERE». It is fed
 * by the thread's presence channel (`chat-presence.{uuid}`), which knows who has
 * THIS thread open and nothing else — and `FR-058` stores no «last seen» anywhere,
 * so there is no other honest source for it.
 *
 * ⚠️ `bg-secondary`, AND THERE IS NO `success` TOKEN. A class naming a token
 * `@theme` never defined paints nothing (four times in this tree); `secondary` is
 * the product's green.
 */
export function PresenceAvatar({
  url,
  name,
  online,
  size = "md",
}: {
  url: string | null;
  name: string;
  online: boolean;
  size?: "sm" | "md";
}) {
  return (
    <span className="relative inline-flex shrink-0">
      <Avatar url={url} name={name} size={size} />
      {online && (
        <span
          data-testid="presence-dot"
          aria-hidden="true"
          className="absolute bottom-0 end-0 size-2.5 rounded-full border-2 border-surface bg-secondary"
        />
      )}
    </span>
  );
}
