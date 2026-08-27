import {
  BellIcon,
  CertificateIcon,
  CreditsIcon,
  ExamIcon,
  MessagesIcon,
  ScheduleIcon,
  SettlementIcon,
  ShieldIcon,
} from "@/components/icons";

/**
 * A glyph per notification subject.
 *
 * ⚠️ AN ICON TABLE, NOT A CLASSIFICATION. The mapping from forty-eight types to
 * seven subjects lives on the server and travels on every row — writing it again
 * here would be a second copy that goes stale the day a type is re-filed, and
 * its staleness would be silent, because a row with the wrong icon still
 * renders. What this file holds is only which picture goes with a subject the
 * server already named.
 *
 * ⚠️ AND THE GLYPH IS NEVER THE CARRIER. Every place it is drawn keeps the word
 * beside it and marks the icon `aria-hidden` — an icon alone is a guess for a
 * reader who does not know the product yet, and nothing at all to a screen
 * reader. Same rule the status badges and the lesson rows follow.
 *
 * The fallback is the bell: a type nobody has classified still arrives, still
 * reads, and still needs something at the start of its row.
 */

const ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
  study: ExamIcon,
  sessions: ScheduleIcon,
  achievements: CertificateIcon,
  messages: MessagesIcon,
  balance: CreditsIcon,
  settlement: SettlementIcon,
  account: ShieldIcon,
};

/**
 * The tone each subject is drawn in — emphasis on a word that already says it.
 *
 * ⚠️ EVERY TOKEN HERE IS ONE `@theme` ACTUALLY DEFINES. Tailwind v4 emits no rule
 * at all for a token it has never seen, so a colour class naming one paints
 * NOTHING — silently, and this tree has shipped three invisible states that way.
 * `accent-ink` in particular does not exist: the accent's only foreground token
 * is `accent-foreground`, which is WHITE and earned against the solid brass fill
 * rather than against a 15% tint of it. Money is drawn in ink on that tint
 * instead.
 */
const TONES: Record<string, string> = {
  study: "bg-primary-soft text-primary-ink",
  sessions: "bg-primary-soft text-primary-ink",
  achievements: "bg-secondary/15 text-secondary-ink",
  messages: "bg-primary-soft text-primary-ink",
  balance: "bg-accent/15 text-ink",
  settlement: "bg-accent/15 text-ink",
  account: "bg-line text-ink-muted",
};

export function categoryIcon(key: string | null, className = "h-4 w-4") {
  const Icon = (key === null ? undefined : ICONS[key]) ?? BellIcon;

  return <Icon className={className} />;
}

export function categoryTone(key: string | null): string {
  return (key === null ? undefined : TONES[key]) ?? "bg-line text-ink-muted";
}
