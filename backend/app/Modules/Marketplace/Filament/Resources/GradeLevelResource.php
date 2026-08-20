<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Modules\Marketplace\Filament\Resources\GradeLevelResource\Pages;
use App\Modules\Marketplace\Models\GradeLevel;
use Filament\Resources\Pages\PageRegistration;

/**
 * The platform's grade levels — see {@see SubjectResource}; everything shared
 * lives in {@see TaxonomyResource}.
 *
 * ⚠️ THE SLUG HERE IS LOAD-BEARING IN ONE MORE PLACE THAN THE SUBJECT'S. A
 * subject is referenced by id through pivots and `courses.subject_id`; a grade
 * level is referenced by its SLUG as free text from `courses.grade_level`, from
 * `student_profiles.grade_level_slug` and from every `grade:{slug}` leaderboard
 * key. Nothing in the database defends any of the three.
 */
class GradeLevelResource extends TaxonomyResource
{
    protected static ?string $model = GradeLevel::class;

    public static function getNavigationLabel(): string
    {
        return 'المراحل الدراسية';
    }

    public static function getModelLabel(): string
    {
        return 'مرحلة دراسية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المراحل الدراسية';
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGradeLevels::route('/'),
            'create' => Pages\CreateGradeLevel::route('/create'),
            'edit' => Pages\EditGradeLevel::route('/{record}/edit'),
        ];
    }
}
