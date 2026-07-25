<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class CreateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::CMS_CREATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published'],
            'category_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:cms_tags,id'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
