import { Avatar } from "@/components/ui/Avatar";

/**
 * A face, with a green dot when the person is here right now.
 *
 * The caller decides what «here» means: in the list it is «on the platform»
 * (`GET /conversations/online`), in a thread's header it is «in this thread»
 * (`chat-presence.{uuid}`). Neither is stored — `FR-058` keeps no «last seen».
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
