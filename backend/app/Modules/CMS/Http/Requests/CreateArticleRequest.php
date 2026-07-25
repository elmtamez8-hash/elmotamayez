<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

class CreateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CMS_CREATE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published'],
            'category_id' => ['nullable', 'integer', WorkspaceRules::exists('cms_categories')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', WorkspaceRules::exists('cms_tags')],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
