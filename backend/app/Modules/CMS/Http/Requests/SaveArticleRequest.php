<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Requests;

use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One request for creating and for editing — and the permission it asks DIFFERS.
 *
 * ⚠️ `cms.create` ON A POST AND `cms.update` ON A PUT, never one of them for
 * both. The matrix gives an assistant-teacher the two together today, so the
 * distinction costs nothing and is invisible — which is exactly why it has to be
 * written now: the day a role holds one without the other, a single spelling
 * hands them the other for free.
 */
class SaveArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $needed = $this->isMethod('POST')
            ? Permissions::CMS_CREATE
            : Permissions::CMS_UPDATE;

        return $this->user()?->can($needed) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('article');

        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            /*
            | ⚠️ UNIQUE ACROSS THE WHOLE PLATFORM, INCLUDING TRASHED ROWS — and
            | without this the answer to a collision is a raw 500. `/blog/{slug}`
            | is ambiguous by definition otherwise: two teachers may both publish
            | «خطة-المراجعة» and the public route has nothing to tell them apart
            | with. `Rule::unique()` is a raw query — no global scopes, no
            | `deleted_at` clause — which is exactly the shape the index has, so
            | the sentence a teacher reads and the constraint the database
            | enforces are the same question.
            |
            | Auto-generation never reaches here: spatie's `HasSlug` resolves a
            | collision to `-2` on the model. This is the door for a slug the
            | teacher TYPED, where silently renaming what they wrote would be
            | worse than refusing it.
            */
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('cms_articles', 'slug')
                    ->ignore($current instanceof Article ? $current->getKey() : null),
            ],
            'status' => ['nullable', 'in:draft,published'],
            /*
            | ⚠️ VALIDATED HERE, UNLIKE THE DELETED API — AND THAT CHANGES WHAT
            | THE CONTROLLER MUST GUARD. The old `/cms/articles` never accepted
            | this field, so `validated()` dropped it and a permission arm for it
            | would have been a guard nothing could exercise. This screen
            | schedules, so the date really can arrive — and a date in the FUTURE
            | de-lists a live article without `status` moving at all, because
            | `publicListingConstraints()` asks `published_at <= now()`. It is the
            | publish capability wearing a date, and it is gated as one.
            */
            'published_at' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer', WorkspaceRules::exists('cms_categories')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', WorkspaceRules::exists('cms_tags')],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            /*
            | An absolute http(s) URL or nothing. It is emitted as
            | `<link rel="canonical">` and inside the article's JSON-LD, so a
            | relative or `javascript:` value is a broken tag on an indexed page
            | at best. The frontend checks it a second time — a rule added today
            | says nothing about the rows already in the table.
            */
            'canonical_url' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }
}
