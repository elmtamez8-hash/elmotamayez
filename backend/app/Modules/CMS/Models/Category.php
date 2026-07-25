<?php

declare(strict_types=1);

namespace App\Modules\CMS\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends BaseModel
{
    use BelongsToWorkspace;

    protected $table = 'cms_categories';

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'parent_id',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
