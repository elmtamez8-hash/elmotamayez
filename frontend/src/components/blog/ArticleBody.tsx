"use client";

import { useLightboxIn } from "@/components/ui/ImageLightbox";

/**
 * An article's rendered body, with every picture in it opening the page's
 * image viewer — the arrows walk the article's pictures in reading order.
 *
 * The HTML is the server's Markdown render with raw HTML stripped at the parse
 * (see the page); nothing here adds markup to it — `useLightboxIn` only reads
 * the `<img>` nodes that are already there.
 */
export function ArticleBody({ html }: { html: string }) {
  const { body, lightbox } = useLightboxIn<HTMLDivElement>(html);

  return (
    <>
      <div className="prose-article" {...body} />
      {lightbox}
    </>
  );
}
