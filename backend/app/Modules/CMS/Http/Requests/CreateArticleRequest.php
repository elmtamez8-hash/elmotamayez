<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Requests;

use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CMS_CREATE) ?? false;
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
            | without this the answer to a collision is a raw 500. Spec 011's own
            | first phase replaced `unique(workspace_id, slug)` with a global
            | `unique(slug)`, because `/blog/{slug}` is ambiguous by definition
            | otherwise: two teachers may both publish «خطة-المراجعة» and the
            | public route has nothing to tell them apart with. `Rule::unique()`
            | is a raw query — no global scopes, no `deleted_at` clause — which is
            | exactly the shape the index has, so the sentence a teacher reads and
            | the constraint the database enforces are the same question.
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
