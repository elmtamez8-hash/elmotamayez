<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base Eloquent model: strict typing, unguarded-by-default off (use $fillable),
 * and a factory-friendly base. All domain models extend this.
 */
abstract class BaseModel extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    public $incrementing = true;

    protected $guarded = [];
}
