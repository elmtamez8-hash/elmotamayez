<?php

declare(strict_types=1);

namespace App\Modules\CMS\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\CMS\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $status
 */
class Article extends BaseModel
{
    use BelongsToWorkspace, HasFactory, HasUuid, IsPublishable;

    protected $table = 'cms_articles';

    protected $fillable = [
        'workspace_id',
        'title',
        'slug',
        'body',
        'excerpt',
        'status',
        'published_at',
        'author_id',
        'category_id',
        'seo_title',
        'seo_description',
        'canonical_url',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'cms_article_tag', 'article_id', 'tag_id');
    }

    protected static function newFactory(): Factory
    {
        return ArticleFactory::new();
    }
}
