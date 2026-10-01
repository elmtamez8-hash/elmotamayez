import { ArticleBody } from "@/components/blog/ArticleBody";

/**
 * Teacher-written Markdown, as the server rendered it (`*_html`), in a compact
 * reading size — a course's «about», an announcement, a homework brief. The same
 * `prose-article` rules as the blog and the lesson page, so a list or a heading
 * looks the same wherever it was written; pictures open the image viewer.
 *
 * Renders nothing for an empty body, so a caller need not guard it.
 */
export function MarkdownText({ html, className = "" }: { html: string | null | undefined; className?: string }) {
  if (!html) return null;

  return (
    <div className={`text-sm ${className}`}>
      <ArticleBody html={html} />
    </div>
  );
}
