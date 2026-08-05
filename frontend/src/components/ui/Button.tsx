import Link from "next/link";
import type { ReactNode } from "react";

/**
 * The one button. Every screen used to write its own `rounded-lg bg-indigo-600
 * px-4 py-2 …` string, which is how a product ends up with four blues.
 *
 * No free-form `className`: appearance comes from a closed set of variants, so a
 * screen cannot quietly drift and SC-004 stays meaningful. See
 * specs/002-arabic-rtl-app-shell/contracts/ui-components.md.
 */

type Variant = "primary" | "accent" | "secondary" | "ghost" | "danger";
type Size = "sm" | "md" | "lg";

const VARIANTS: Record<Variant, string> = {
  primary: "bg-primary text-white hover:brightness-110",
  // The marketplace conversion colour. Its foreground is dark, not white —
  // accent reaches only 2.4:1 against white.
  accent: "bg-accent text-accent-foreground hover:brightness-105",
  secondary: "border border-line bg-surface-raised text-ink hover:bg-primary-soft hover:text-primary-ink",
  ghost: "text-ink hover:bg-primary-soft hover:text-primary-ink",
  danger: "bg-danger text-white hover:brightness-110",
};

const SIZES: Record<Size, string> = {
  sm: "px-3 py-1.5 text-xs",
  // md and lg clear the 44px touch target; sm is for dense table rows where the
  // row itself is the target.
  md: "px-4 py-2.5 text-sm",
  lg: "px-6 py-3 text-base",
};

const BASE =
  "inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition " +
  "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary " +
  "disabled:cursor-not-allowed disabled:opacity-60";

type CommonProps = {
  children: ReactNode;
  variant?: Variant;
  size?: Size;
  iconStart?: ReactNode;
  iconEnd?: ReactNode;
  fullWidth?: boolean;
};

type ButtonProps = CommonProps & {
  type?: "button" | "submit";
  disabled?: boolean;
  /** Disables the button and announces the wait — also blocks double submits. */
  loading?: boolean;
  loadingLabel?: string;
  onClick?: () => void;
  href?: never;
};

type LinkProps = CommonProps & {
  href: string;
  /** Set for downloads and external targets; internal links stay client-side. */
  external?: boolean;
};

function classesFor({ variant = "primary", size = "md", fullWidth }: CommonProps) {
  return `${BASE} ${VARIANTS[variant]} ${SIZES[size]} ${fullWidth ? "w-full" : ""}`;
}

export function Button(props: ButtonProps | LinkProps) {
  const { children, iconStart, iconEnd } = props;
  const className = classesFor(props);

  if ("href" in props && props.href !== undefined) {
    const { href, external } = props;

    if (external) {
      return (
        <a href={href} className={className} rel="noopener noreferrer" target="_blank">
          {iconStart}
          {children}
          {iconEnd}
        </a>
      );
    }

    return (
      <Link href={href} className={className}>
        {iconStart}
        {children}
        {iconEnd}
      </Link>
    );
  }

  const { type = "button", disabled, loading, loadingLabel, onClick } = props as ButtonProps;

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={className}
    >
      {loading ? (
        <>
          <span
            aria-hidden="true"
            className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"
          />
          {loadingLabel ?? "جارٍ التنفيذ…"}
        </>
      ) : (
        <>
          {iconStart}
          {children}
          {iconEnd}
        </>
      )}
    </button>
  );
}
