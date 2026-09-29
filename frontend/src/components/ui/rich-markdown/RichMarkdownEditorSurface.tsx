"use client";

import { Extension } from "@tiptap/core";
import type { Editor } from "@tiptap/core";
import { EditorContent, useEditor, useEditorState } from "@tiptap/react";
import { useEffect, useMemo, useRef, useState } from "react";
import type { ComponentType } from "react";

import {
  BoldIcon,
  BulletListIcon,
  CodeBlockIcon,
  DividerIcon,
  Heading2Icon,
  Heading3Icon,
  InlineCodeIcon,
  ItalicIcon,
  LinkIcon,
  NumberedListIcon,
  QuoteIcon,
  RedoIcon,
  UndoIcon,
  UnlinkIcon,
} from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { CONTROL } from "@/components/ui/Field";
import type { RichMarkdownEditorSurfaceProps } from "@/components/ui/RichMarkdownEditor";

import { richMarkdownExtensions, safeHref, toMarkdown } from "./extensions";

/**
 * The editing surface behind `RichMarkdownEditor` — its own chunk, loaded by
 * `next/dynamic` with `ssr: false`, so nothing here runs on the server and no
 * page pays for ProseMirror until the field is on screen.
 *
 * The body uses `.prose-article`, the class the published article is drawn
 * with, so what the author sees while typing is what the reader gets: RTL
 * bullets, the blockquote rule on the inline start, code blocks kept LTR.
 */
export default function RichMarkdownEditorSurface({
  id,
  labelId,
  describedBy,
  invalid,
  disabled,
  value,
  onChange,
}: RichMarkdownEditorSurfaceProps) {
  const [linkOpen, setLinkOpen] = useState(false);
  const openLinkRef = useRef<() => void>(() => undefined);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const lastEmittedRef = useRef(value.trimEnd());

  const extensions = useMemo(
    () => [
      ...richMarkdownExtensions(),
      // Ctrl/⌘+K opens the link box, the shortcut every editor people know uses.
      Extension.create({
        name: "linkShortcut",
        addKeyboardShortcuts: () => ({
          "Mod-k": () => {
            openLinkRef.current();
            return true;
          },
        }),
      }),
    ],
    [],
  );

  const editor = useEditor({
    extensions,
    content: value,
    contentType: "markdown",
    editable: !disabled,
    // Required with SSR in the tree: the first render must match the server's,
    // which drew nothing here.
    immediatelyRender: false,
    // The toolbar reads its state through `useEditorState`; re-rendering the
    // whole surface on every keystroke would buy nothing.
    shouldRerenderOnTransaction: false,
    editorProps: {
      attributes: {
        id,
        role: "textbox",
        "aria-multiline": "true",
        "aria-labelledby": labelId,
        ...(describedBy ? { "aria-describedby": describedBy } : {}),
        ...(invalid ? { "aria-invalid": "true" } : {}),
        dir: "rtl",
        class:
          "prose-article min-h-64 max-h-[70vh] overflow-y-auto px-4 py-3 text-base focus:outline-none",
      },
    },
    // Only a change of the MARKDOWN is reported. The editor also changes its
    // document for reasons of its own (a trailing paragraph for the caret, a
    // normalised empty node) and those serialise to the same source — reporting
    // them would mark an untouched article as edited. Opening an article and
    // saving it untouched sends back the Markdown it came with, byte for byte.
    onUpdate: ({ editor: e }) => {
      const markdown = toMarkdown(e);
      if (markdown === lastEmittedRef.current) return;
      lastEmittedRef.current = markdown;
      onChangeRef.current(markdown);
    },
  });

  useEffect(() => {
    editor?.setEditable(!disabled);
  }, [editor, disabled]);

  openLinkRef.current = () => {
    if (!disabled) setLinkOpen(true);
  };

  return (
    <div
      className={
        "overflow-hidden rounded-xl border bg-surface-raised transition " +
        "focus-within:outline focus-within:outline-2 focus-within:outline-offset-1 focus-within:outline-primary " +
        (invalid ? "border-danger " : "border-line ") +
        (disabled ? "opacity-60" : "")
      }
    >
      {editor ? (
        <Toolbar
          editor={editor}
          controls={id}
          disabled={disabled}
          onLink={() => setLinkOpen((open) => !open)}
          linkOpen={linkOpen}
        />
      ) : (
        <div className="h-12 border-b border-line" aria-hidden="true" />
      )}

      {editor && linkOpen ? (
        <LinkForm
          key={editor.getAttributes("link").href ?? "new"}
          editor={editor}
          fieldId={`${id}-link-url`}
          onClose={() => {
            setLinkOpen(false);
            editor.commands.focus();
          }}
        />
      ) : null}

      <EditorContent editor={editor} />
    </div>
  );
}

type ToolState = {
  bold: boolean;
  italic: boolean;
  h2: boolean;
  h3: boolean;
  bullet: boolean;
  ordered: boolean;
  quote: boolean;
  code: boolean;
  codeBlock: boolean;
  link: boolean;
  canUndo: boolean;
  canRedo: boolean;
};

function Toolbar({
  editor,
  controls,
  disabled,
  onLink,
  linkOpen,
}: {
  editor: Editor;
  controls: string;
  disabled: boolean;
  onLink: () => void;
  linkOpen: boolean;
}) {
  const state = useEditorState<ToolState>({
    editor,
    selector: ({ editor: e }) => ({
      bold: e.isActive("bold"),
      italic: e.isActive("italic"),
      h2: e.isActive("heading", { level: 2 }),
      h3: e.isActive("heading", { level: 3 }),
      bullet: e.isActive("bulletList"),
      ordered: e.isActive("orderedList"),
      quote: e.isActive("blockquote"),
      code: e.isActive("code"),
      codeBlock: e.isActive("codeBlock"),
      link: e.isActive("link"),
      canUndo: e.can().undo(),
      canRedo: e.can().redo(),
    }),
  });

  const run = (fn: (chain: ReturnType<Editor["chain"]>) => ReturnType<Editor["chain"]>) =>
    fn(editor.chain().focus()).run();

  return (
    <div
      role="toolbar"
      aria-label="أدوات التنسيق"
      aria-controls={controls}
      className="flex flex-wrap items-center gap-0.5 border-b border-line bg-surface p-1.5"
    >
      <Tool
        label="غامق"
        shortcut="Ctrl+B"
        Icon={BoldIcon}
        pressed={state.bold}
        disabled={disabled}
        onPress={() => run((c) => c.toggleBold())}
      />
      <Tool
        label="مائل"
        shortcut="Ctrl+I"
        Icon={ItalicIcon}
        pressed={state.italic}
        disabled={disabled}
        onPress={() => run((c) => c.toggleItalic())}
      />
      <Separator />
      <Tool
        label="عنوان رئيسي"
        shortcut="Ctrl+Alt+2"
        Icon={Heading2Icon}
        pressed={state.h2}
        disabled={disabled}
        onPress={() => run((c) => c.toggleHeading({ level: 2 }))}
      />
      <Tool
        label="عنوان فرعي"
        shortcut="Ctrl+Alt+3"
        Icon={Heading3Icon}
        pressed={state.h3}
        disabled={disabled}
        onPress={() => run((c) => c.toggleHeading({ level: 3 }))}
      />
      <Separator />
      <Tool
        label="قائمة نقطية"
        shortcut="Ctrl+Shift+8"
        Icon={BulletListIcon}
        pressed={state.bullet}
        disabled={disabled}
        onPress={() => run((c) => c.toggleBulletList())}
      />
      <Tool
        label="قائمة مرقّمة"
        shortcut="Ctrl+Shift+7"
        Icon={NumberedListIcon}
        pressed={state.ordered}
        disabled={disabled}
        onPress={() => run((c) => c.toggleOrderedList())}
      />
      <Tool
        label="اقتباس"
        shortcut="Ctrl+Shift+B"
        Icon={QuoteIcon}
        pressed={state.quote}
        disabled={disabled}
        onPress={() => run((c) => c.toggleBlockquote())}
      />
      <Separator />
      <Tool
        label={state.link ? "تعديل الرابط" : "إضافة رابط"}
        shortcut="Ctrl+K"
        Icon={LinkIcon}
        pressed={state.link || linkOpen}
        disabled={disabled}
        onPress={onLink}
      />
      <Tool
        label="كود داخل السطر"
        shortcut="Ctrl+E"
        Icon={InlineCodeIcon}
        pressed={state.code}
        disabled={disabled}
        onPress={() => run((c) => c.toggleCode())}
      />
      <Tool
        label="كتلة كود"
        shortcut="Ctrl+Alt+C"
        Icon={CodeBlockIcon}
        pressed={state.codeBlock}
        disabled={disabled}
        onPress={() => run((c) => c.toggleCodeBlock())}
      />
      <Tool
        label="خط فاصل"
        Icon={DividerIcon}
        disabled={disabled}
        onPress={() => run((c) => c.setHorizontalRule())}
      />
      <Separator />
      <Tool
        label="تراجع"
        shortcut="Ctrl+Z"
        Icon={UndoIcon}
        disabled={disabled || !state.canUndo}
        onPress={() => run((c) => c.undo())}
      />
      <Tool
        label="إعادة"
        shortcut="Ctrl+Shift+Z"
        Icon={RedoIcon}
        disabled={disabled || !state.canRedo}
        onPress={() => run((c) => c.redo())}
      />
    </div>
  );
}

function Separator() {
  return <span className="mx-1 h-5 w-px bg-line" aria-hidden="true" />;
}

function Tool({
  label,
  shortcut,
  Icon,
  pressed,
  disabled,
  onPress,
}: {
  label: string;
  shortcut?: string;
  Icon: ComponentType<{ className?: string }>;
  /** Omitted for an action (a rule, undo) — only a toggle announces a state. */
  pressed?: boolean;
  disabled: boolean;
  onPress: () => void;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={shortcut ? `${label} (${shortcut})` : label}
      aria-pressed={pressed}
      aria-keyshortcuts={shortcut?.replace("Ctrl", "Control")}
      disabled={disabled}
      // Keep the selection: a mousedown that moves focus to the button would
      // collapse the text the writer just selected before the command runs.
      onMouseDown={(event) => event.preventDefault()}
      onClick={onPress}
      className={
        "inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink transition " +
        "hover:bg-primary-soft hover:text-primary-ink " +
        "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary " +
        "disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-ink " +
        (pressed ? "bg-primary-soft text-primary-ink" : "")
      }
    >
      <Icon className="h-4 w-4" />
    </button>
  );
}

/**
 * The link box — a row under the toolbar, not `window.prompt`, so it is styled,
 * labelled, and does not freeze the page.
 */
function LinkForm({
  editor,
  fieldId,
  onClose,
}: {
  editor: Editor;
  fieldId: string;
  onClose: () => void;
}) {
  const current = (editor.getAttributes("link").href as string | undefined) ?? "";
  const [draft, setDraft] = useState(current);
  const [error, setError] = useState<string | null>(null);

  const apply = () => {
    const href = safeHref(draft);
    if (href === null) {
      setError("اكتب رابطاً يبدأ بـ https:// أو عنوان بريد بـ mailto:");
      return;
    }

    const { empty } = editor.state.selection;
    if (empty && !editor.isActive("link")) {
      // Nothing selected: the address itself becomes the linked text.
      editor
        .chain()
        .focus()
        .insertContent({ type: "text", text: draft.trim(), marks: [{ type: "link", attrs: { href } }] })
        .run();
    } else {
      editor.chain().focus().extendMarkRange("link").setLink({ href }).run();
    }
    onClose();
  };

  const remove = () => {
    editor.chain().focus().extendMarkRange("link").unsetLink().run();
    onClose();
  };

  return (
    <div className="flex flex-wrap items-start gap-2 border-b border-line bg-surface p-2">
      <div className="min-w-0 flex-1 basis-56 space-y-1">
        <label htmlFor={fieldId} className="sr-only">
          عنوان الرابط
        </label>
        <input
          id={fieldId}
          type="url"
          dir="ltr"
          inputMode="url"
          autoFocus
          placeholder="https://"
          value={draft}
          onChange={(event) => {
            setDraft(event.target.value);
            setError(null);
          }}
          onKeyDown={(event) => {
            if (event.key === "Enter") {
              event.preventDefault();
              apply();
            } else if (event.key === "Escape") {
              event.preventDefault();
              onClose();
            }
          }}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${fieldId}-error` : undefined}
          className={`${CONTROL} ${error ? "border-danger" : "border-line"}`}
        />
        {error ? (
          <p id={`${fieldId}-error`} role="alert" className="text-xs font-medium text-danger-ink">
            {error}
          </p>
        ) : null}
      </div>
      <div className="flex flex-wrap gap-1">
        <Button type="button" size="sm" onClick={apply}>
          تطبيق
        </Button>
        {current ? (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            iconStart={<UnlinkIcon className="h-4 w-4" />}
            onClick={remove}
          >
            إزالة الرابط
          </Button>
        ) : null}
        <Button type="button" size="sm" variant="ghost" onClick={onClose}>
          إلغاء
        </Button>
      </div>
    </div>
  );
}
