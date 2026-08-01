"use client";

/**
 * Submit button that refuses the second click.
 *
 * `disabled` alone drops the button out of the tab order mid-interaction, so a
 * screen-reader user loses their place. `aria-busy` plus the swapped label says
 * what is happening instead of going silent.
 */
export function SubmitButton({
  loading,
  children,
  loadingLabel = "جارٍ الإرسال…",
}: {
  loading: boolean;
  children: React.ReactNode;
  loadingLabel?: string;
}) {
  return (
    <button
      type="submit"
      disabled={loading}
      aria-busy={loading}
      className="flex w-full items-center justify-center gap-2 rounded-xl bg-accent px-5 py-3 text-base font-semibold text-accent-foreground transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-60"
    >
      {loading && (
        <span
          className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none"
          aria-hidden="true"
        />
      )}
      {loading ? loadingLabel : children}
    </button>
  );
}
