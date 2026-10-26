"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { useEffect, useRef, useTransition, type ReactNode } from "react";
import { CloseIcon } from "@/components/icons";
import { Select } from "@/components/ui/Field";
import { arabicNumber } from "@/lib/numerals";
import type { Taxonomy } from "@/lib/public-api";
import { subjectIcon } from "./subject-icon";

/**
 * The pieces the teachers and courses filters share (owner redesign,
 * 2026-10-23): toggle chips, compact selects, the URL writer and the phone's
 * bottom sheet. Filter state lives in the URL, so a filtered result is
 * shareable and the page stays a Server Component (FR-051, SC-015).
 */

/** How many subjects get a chip; the rest go in «مواد أخرى». */
const SUBJECT_CHIPS = 5;

export const chip = (pressed: boolean) =>
  "inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-semibold transition duration-200 " +
  "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary " +
  (pressed
    ? "border-primary bg-primary text-white shadow-sm shadow-primary/20"
    : "border-line bg-surface-raised text-ink hover:border-primary/40 hover:bg-primary-soft hover:text-primary-ink");

export const pill =
  "rounded-full border border-line bg-surface-raised px-4 py-2.5 text-sm text-ink transition duration-200 hover:border-primary/50 " +
  "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary";

/**
 * `stageScopesSubjects`: the page fetched the subjects for the chosen stage, so
 * changing the stage must clear the subject — a subject not taught at the new
 * stage has no option, and the control would say «كل المواد» while the URL
 * still filtered by it.
 */
export function useFilterUrl({ stageScopesSubjects = false } = {}) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [pending, startTransition] = useTransition();

  const update = (key: string, value: string) => {
    const next = new URLSearchParams(params.toString());

    if (value === "") next.delete(key);
    else next.set(key, value);

    // Any filter change resets to page 1; keeping the old page number is how a
    // narrowed search lands on an empty page that looks like "no results".
    next.delete("page");

    if (stageScopesSubjects && key === "grade_level") next.delete("subject");

    const query = next.toString();

    startTransition(() => router.push(query === "" ? pathname : `${pathname}?${query}`, { scroll: false }));
  };

  const clear = () => startTransition(() => router.push(pathname, { scroll: false }));
  const get = (key: string) => params.get(key) ?? "";

  return { get, pending, update, clear };
}

/** One choice in the sheet: a toggle button, exactly one pressed per group. */
export function Option({ pressed, onClick, children }: { pressed: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button type="button" aria-pressed={pressed} onClick={onClick} className={chip(pressed)}>
      {children}
    </button>
  );
}

export function SheetGroup({ title, children }: { title: string; children: ReactNode }) {
  return (
    <fieldset className="border-t border-line py-4 first:border-t-0 first:pt-0">
      <legend className="mb-3 text-sm font-extrabold text-ink">{title}</legend>
      <div className="flex flex-wrap gap-2">{children}</div>
    </fieldset>
  );
}

/** The phone's «فلاتر» button, with how many sheet filters are on. */
export function SheetButton({ count, onClick }: { count: number; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="inline-flex shrink-0 items-center gap-2 rounded-full bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-primary/20 transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:hidden"
    >
      فلاتر
      {count > 0 && (
        <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-white px-1.5 text-xs font-bold text-primary-ink">
          {arabicNumber(count)}
        </span>
      )}
    </button>
  );
}

/**
 * The phone's bottom sheet. Every change applies at once — the list behind
 * updates while it is open — so «اعرض النتائج» only closes it.
 */
export function FilterSheet({
  id,
  open,
  onClose,
  onClear,
  children,
}: {
  id: string;
  open: boolean;
  onClose: () => void;
  onClear: () => void;
  children: ReactNode;
}) {
  const sheet = useRef<HTMLDialogElement>(null);

  // `showModal()`, not the `open` attribute: the method is what traps focus,
  // makes the page behind inert and wires Escape (see `ui/Modal`).
  useEffect(() => {
    const dialog = sheet.current;
    if (dialog === null) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  return (
    <dialog
      ref={sheet}
      aria-labelledby={`${id}-title`}
      onClose={onClose}
      onClick={(event) => {
        // Only a press on the backdrop itself (see `ui/Modal`, C-06).
        if (event.target === sheet.current) sheet.current?.close();
      }}
      className="sheet-up fixed inset-x-0 bottom-0 top-auto m-0 max-h-[85vh] open:flex w-full max-w-full flex-col rounded-t-3xl border-t border-line bg-surface-raised p-0 text-start text-ink shadow-2xl backdrop:bg-overlay lg:hidden"
    >
      <div className="flex items-center justify-between border-b border-line px-5 py-4">
        <h2 id={`${id}-title`} className="text-lg font-extrabold">فلاتر</h2>
        <button
          type="button"
          onClick={onClose}
          aria-label="إغلاق"
          className="grid h-9 w-9 place-items-center rounded-full text-ink-muted transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        >
          <CloseIcon className="h-4 w-4" />
        </button>
      </div>

      <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>

      <div className="flex gap-3 border-t border-line px-5 py-4">
        <button
          type="button"
          onClick={onClear}
          className="rounded-full border border-line px-5 py-3 text-sm font-semibold text-ink transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        >
          مسح الكل
        </button>
        <button
          type="button"
          onClick={onClose}
          className="flex-1 rounded-full bg-primary px-5 py-3 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          اعرض النتائج
        </button>
      </div>
    </dialog>
  );
}

/**
 * The subject row: «كل المواد», the most-taught subjects as one-tap chips, and
 * the rest in one «مواد أخرى» select. Chips WRAP and never scroll sideways (the
 * rule `FilterBar` records); the cap keeps a growing list from pushing the
 * first result off a phone.
 */
export function SubjectChips({
  id,
  subjects,
  subject,
  allLabel,
  onPick,
}: {
  id: string;
  subjects: Taxonomy[];
  subject: string;
  allLabel: string;
  onPick: (slug: string) => void;
}) {
  const ranked = rankSubjects(subjects);
  const chipSubjects = ranked.slice(0, SUBJECT_CHIPS);
  const otherSubjects = ranked.slice(SUBJECT_CHIPS);
  const otherChosen = otherSubjects.some((item) => item.slug === subject);

  return (
    <div role="group" aria-label="المادة" className="mt-4 flex flex-wrap items-center gap-2">
      <button type="button" aria-pressed={subject === ""} onClick={() => onPick("")} className={chip(subject === "")}>
        {allLabel}
      </button>
      {chipSubjects.map((item) => {
        const Icon = subjectIcon(item);
        const pressed = item.slug === subject;

        return (
          <button
            key={item.slug}
            type="button"
            aria-pressed={pressed}
            onClick={() => onPick(pressed ? "" : item.slug)}
            className={chip(pressed)}
          >
            <Icon className="h-4 w-4" />
            {item.name}
          </button>
        );
      })}
      {otherSubjects.length > 0 && (
        <>
          <label htmlFor={`${id}-subject-more`} className="sr-only">مواد أخرى</label>
          <Select
            id={`${id}-subject-more`}
            value={otherChosen ? subject : ""}
            onChange={(event) => onPick(event.target.value)}
            className={`${pill} py-2 font-semibold ${otherChosen ? "border-primary text-primary-ink" : ""}`}
          >
            <option value="">مواد أخرى</option>
            {otherSubjects.map((item) => (
              <option key={item.slug} value={item.slug}>{item.name}</option>
            ))}
          </Select>
        </>
      )}
    </div>
  );
}

/** Most-taught first — the order the chips and the sheet both use. */
export const rankSubjects = (subjects: Taxonomy[]) =>
  [...subjects].sort((a, b) => (b.teachers_count ?? 0) - (a.teachers_count ?? 0));
