"use client";

import dynamic from "next/dynamic";

/**
 * A rich-text (WYSIWYG) field that reads and writes MARKDOWN.
 *
 * ⚠️ **THE STORED VALUE IS STILL MARKDOWN, AND THAT IS THE WHOLE POINT.** The
 * rule «authored text is Markdown, rendered per response, never stored as HTML»
 * (`docs/gotchas/courses.md`) is untouched: `value` comes in as Markdown and
 * `onChange` hands Markdown back, so the backend contract, the server-side
 * render and its `html_input: strip` stay exactly as they were. The editor is a
 * nicer way to TYPE the same source, not a new format. What it can produce is
 * the CommonMark-core set the server renders and nothing more — see
 * `rich-markdown/extensions.ts` for the list and for why each missing button
 * (underline, strikethrough, tables, colour) is missing.
 *
 * ⚠️ `value` is read ONCE, when the editor mounts. It does not follow later
 * changes of the prop — re-setting a live editor's content on every keystroke
 * is the caret-jumps-to-the-end bug. A caller that swaps to a DIFFERENT
 * document gives the component a new `key` so it remounts.
 *
 * The editor (ProseMirror + Tiptap + the Markdown parser) is a separate chunk,
 * loaded on the client only when this field is on screen; a page that never
 * opens the form never downloads it. Until it arrives, a box of the same height
 * holds the space so the form below does not jump.
 *
 * Why not `Field`: its `<label htmlFor>` names a form control, and the editing
 * surface is a `contenteditable`, which a label cannot target. The label here is
 * wired by `aria-labelledby` instead, and a click on it moves focus in by hand.
 */

export type RichMarkdownEditorProps = {
  id: string;
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  disabled?: boolean;
  /** Markdown. Read on mount only — see above. */
  value: string;
  /** Receives Markdown, never HTML. */
  onChange: (markdown: string) => void;
};

export type RichMarkdownEditorSurfaceProps = {
  id: string;
  labelId: string;
  describedBy?: string;
  invalid: boolean;
  disabled: boolean;
  value: string;
  onChange: (markdown: string) => void;
};

const Surface = dynamic<RichMarkdownEditorSurfaceProps>(
  () => import("./rich-markdown/RichMarkdownEditorSurface"),
  {
    ssr: false,
    loading: () => (
      <div
        className="h-80 animate-pulse rounded-xl border border-line bg-surface-raised"
        aria-hidden="true"
      />
    ),
  },
);

export function RichMarkdownEditor({
  id,
  label,
  hint,
  error,
  required,
  disabled = false,
  value,
  onChange,
}: RichMarkdownEditorProps) {
  const labelId = `${id}-label`;
  const describedBy =
    [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(" ") ||
    undefined;

  return (
    <div className="space-y-1">
      <span
        id={labelId}
        className="block text-sm font-medium text-ink"
        // The surface carries this id; a label cannot focus a contenteditable.
        onClick={() => document.getElementById(id)?.focus()}
      >
        {label}
        {required && (
          <span className="text-danger-ink" aria-hidden="true">
            {" *"}
          </span>
        )}
      </span>

      {hint && (
        <p id={`${id}-hint`} className="text-xs text-ink-muted">
          {hint}
        </p>
      )}

      <Surface
        id={id}
        labelId={labelId}
        describedBy={describedBy}
        invalid={Boolean(error)}
        disabled={disabled}
        value={value}
        onChange={onChange}
      />

      {error && (
        <p id={`${id}-error`} role="alert" className="text-xs font-medium text-danger-ink">
          {error}
        </p>
      )}
    </div>
  );
}
