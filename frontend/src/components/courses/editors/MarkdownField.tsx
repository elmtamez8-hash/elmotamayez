"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { TextareaField } from "@/components/ui/Field";

/**
 * A Markdown body with a closed formatting toolbar and a preview.
 *
 * **Markdown, not a rich-text editor.** A WYSIWYG surface stores HTML, and
 * stored HTML makes the sanitiser's allowlist the security boundary — one tag
 * nobody thought about is a script running in another student's session. Here
 * the allowlist IS the Markdown feature set, and the server strips raw HTML
 * rather than escaping it. There is no tag to get wrong.
 *
 * The toolbar is a closed set for the same reason: six buttons that each wrap
 * the selection in syntax the renderer already understands. Nothing here can
 * produce output the server would refuse.
 *
 * The preview shows the **last saved** version, rendered by the server. A live
 * preview would need a Markdown parser in the browser — a second implementation
 * of the rendering rules, which is exactly how a preview starts lying.
 */

type Wrap = { label: string; prefix: string; suffix: string };

const TOOLS: Wrap[] = [
  { label: "غامق", prefix: "**", suffix: "**" },
  { label: "مائل", prefix: "_", suffix: "_" },
  { label: "عنوان", prefix: "## ", suffix: "" },
  { label: "قائمة", prefix: "- ", suffix: "" },
  { label: "اقتباس", prefix: "> ", suffix: "" },
  { label: "رابط", prefix: "[", suffix: "](https://)" },
];

export function MarkdownField({
  id,
  label,
  hint,
  value,
  savedHtml,
  disabled,
  onChange,
}: {
  id: string;
  label: string;
  hint?: string;
  value: string;
  /** Server-rendered HTML of the last saved body. */
  savedHtml: string;
  disabled?: boolean;
  onChange: (next: string) => void;
}) {
  const [preview, setPreview] = useState(false);

  const apply = (tool: Wrap) => {
    // Read through the DOM rather than a ref: TextareaField owns the element and
    // takes no ref, and the id it renders is the one passed in. Reaching for the
    // element is cheaper than widening the shared component for one caller.
    const el = document.getElementById(id);
    if (!(el instanceof HTMLTextAreaElement)) return;

    const { selectionStart: start, selectionEnd: end } = el;
    const selected = value.slice(start, end);

    onChange(`${value.slice(0, start)}${tool.prefix}${selected}${tool.suffix}${value.slice(end)}`);

    // Put the caret back where the writer left it, inside the new syntax.
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(start + tool.prefix.length, end + tool.prefix.length);
    });
  };

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        {TOOLS.map((tool) => (
          <Button
            key={tool.label}
            type="button"
            variant="ghost"
            size="sm"
            disabled={disabled || preview}
            onClick={() => apply(tool)}
          >
            {tool.label}
          </Button>
        ))}

        <Button type="button" variant="secondary" size="sm" onClick={() => setPreview((on) => !on)}>
          {preview ? "تحرير" : "معاينة"}
        </Button>
      </div>

      {preview ? (
        <div className="space-y-2">
          <div
            className="min-h-32 rounded-xl border border-line bg-surface-raised p-3 text-sm text-ink"
            // Produced by the server from the Markdown source with raw HTML
            // stripped, not escaped — the same string the student is served.
            // MarkdownSanitisationTest fails the build if a script, an
            // `onerror` attribute or a `javascript:` href survives that path.
            dangerouslySetInnerHTML={{ __html: savedHtml }}
          />
          <p className="text-xs text-ink-muted">
            المعاينة تعرض آخر نسخة محفوظة. احفظ لترى تعديلاتك الأخيرة فيها.
          </p>
        </div>
      ) : (
        <TextareaField
          id={id}
          label={label}
          hint={hint}
          rows={12}
          value={value}
          disabled={disabled}
          onChange={onChange}
        />
      )}
    </div>
  );
}
