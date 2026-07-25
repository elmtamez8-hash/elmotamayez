<?php

declare(strict_types=1);

namespace App\Modules\CMS\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends BaseModel
{
    use BelongsToWorkspace;

    protected $table = 'cms_tags';

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
    ];

    /** @return BelongsToMany<Article, $this> */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'cms_article_tag', 'tag_id', 'article_id');
    }
}
