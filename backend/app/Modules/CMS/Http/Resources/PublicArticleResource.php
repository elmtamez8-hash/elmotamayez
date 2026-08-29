<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Resources;

use App\Modules\CMS\Models\Article;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\MarkdownRenderer;
use App\Modules\Marketplace\Http\Resources\PublicCourseCardResource;
use App\Modules\Marketplace\Http\Resources\PublicTeacherCardResource;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The public face of an article (011 · US5 · FR-032 · FR-034).
 *
 * ⚠️ A SECOND RESOURCE RATHER THAN A BRANCH INSIDE `ArticleResource`. That one is
 * the authenticated author's view and legitimately carries `status`, the raw
 * Markdown `body`, `category.id` and every `tags[].id` — three keys that are in
 * `PublicFieldAllowlist::FORBIDDEN` and a fourth that tells a search engine an
 * article is a draft. One resource branching on the reader is one forgotten
 * branch away from publishing all four.
 *
 * Every key is written out by hand. Never `parent::toArray()`: that publishes
 * whatever column the table gains next, to every anonymous visitor.
 *
 * ⚠️ AND THE AUTHOR IS ABSENT ON PURPOSE (FR-034). `cms_articles.author_id` names
 * a `users` row, and nothing on `users` is public — the byline a reader wants is
 * the TEACHER, which the related-teachers block already carries from the
 * marketplace, where it is published by decision rather than by inheritance.
 *
 * @mixin Article
 */
class PublicArticleResource extends JsonResource
{
    private bool $detail = false;

    /** @var array<string, mixed>|null */
    private ?array $related = null;

    /**
     * The full article page, with FR-038's related links.
     *
     * @param  Collection<int, TeacherProfile>  $teachers
     * @param  Collection<int, Course>  $courses
     */
    public static function detail(Article $article, Collection $teachers, Collection $courses): self
    {
        $resource = new self($article);
        $resource->detail = true;
        $resource->related = [
            /*
            | ⚠️ MARKETPLACE'S OWN CARDS, NOT A SHAPE OF OURS. FR-038 forbids a
            | related link to a suspended or non-participating teacher — which is
            | `publiclyListed()`, enforced in the Action that fetched these rows.
            | Restating the card here would be a second spelling of «what a
            | teacher card contains», and the two would drift at the first field
            | either side adds.
            |
            | `->resolve()` rather than the collection object: this array is
            | handed to a cache-friendly caller in exactly the shape the
            | marketplace cards already had to fix for — a nested
            | `AnonymousResourceCollection` survives `resolve()` as an OBJECT and
            | comes back from a cache store as `__PHP_Incomplete_Class`.
            */
            'related_teachers' => PublicTeacherCardResource::collection($teachers)->resolve(),
            'related_courses' => PublicCourseCardResource::collection($courses)->resolve(),
        ];

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payload = [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'published_at' => $this->published_at,
            // The sitemap's `lastModified` and the article's «آخر تحديث» line.
            'updated_at' => $this->updated_at,
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category === null ? null : [
                // No `id`: it is in FORBIDDEN, and a category is addressed by its
                // slug on every public surface anyway.
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ]),
            'tags' => $this->whenLoaded('tags', fn (): array => $this->tags
                ->map(fn ($tag): array => ['slug' => $tag->slug, 'name' => $tag->name])
                ->values()
                ->all()),
        ];

        if (! $this->detail) {
            return $payload;
        }

        return [
            ...$payload,
            // Derived per response, never stored. Raw HTML in the source is
            // stripped rather than escaped, so the allowlist is the Markdown
            // feature set itself and there is no sanitiser configuration to get
            // wrong.
            'body_html' => MarkdownRenderer::toHtml($this->body),
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'canonical_url' => $this->canonical_url,
            ...($this->related ?? []),
        ];
    }
}
