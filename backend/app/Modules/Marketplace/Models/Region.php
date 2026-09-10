<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\RegionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Translatable\HasTranslations;

/**
 * A place a student lives (spec 011 · FR-042).
 *
 * Platform reference data, and — unlike {@see GradeLevel} — it never carried a
 * workspace column at all. The write door is {@see TaxonomyPolicy} +
 * `taxonomy.manage`; the read door is public and unauthenticated, because the
 * registration form needs the list before there is an account to authenticate.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property bool $is_active
 */
class Region extends BaseModel
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory, HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
