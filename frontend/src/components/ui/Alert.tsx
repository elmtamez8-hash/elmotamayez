import type { ReactNode } from "react";
import { AlertIcon, CheckIcon, InfoIcon } from "@/components/icons";
import { TONE_CLASSES, type StatusTone } from "@/lib/labels";

/**
 * Inline message above a form or a list.
 *
 * Every tone carries an icon as well as a colour: colour alone is not a message
 * for anyone who cannot separate red from green, and "the red box" is how these
 * get described in bug reports.
 *
 * `role` differs by tone on purpose — a screen reader should interrupt for a
 * failure and wait its turn for a confirmation.
 */

const ICONS: Record<StatusTone, typeof AlertIcon> = {
  info: InfoIcon,
  success: CheckIcon,
  warning: AlertIcon,
  danger: AlertIcon,
  neutral: InfoIcon,
};

export function Alert({
  tone,
  title,
  children,
}: {
  tone: Exclude<StatusTone, "neutral">;
  title: string;
  children?: ReactNode;
}) {
  const Icon = ICONS[tone];

  return (
    <div
      role={tone === "danger" ? "alert" : "status"}
      className={`flex items-start gap-3 rounded-xl p-3 text-sm ${TONE_CLASSES[tone]}`}
    >
      <Icon className="mt-0.5 h-4 w-4 shrink-0" />
      <div className="min-w-0">
        <p className="font-medium">{title}</p>
        {children && <div className="mt-1">{children}</div>}
      </div>
    </div>
  );
}
