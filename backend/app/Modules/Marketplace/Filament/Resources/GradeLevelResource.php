<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Modules\Marketplace\Filament\Resources\GradeLevelResource\Pages;
use App\Modules\Marketplace\Filament\Resources\TaxonomyResource\RelationManagers\TeacherProfilesRelationManager;
use App\Modules\Marketplace\Models\GradeLevel;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'السوق والتصنيف';

    protected static ?int $navigationSort = 30;

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

    /**
     * مَن يدرّسُ هذه المرحلةَ فعلاً — نفسُ مديرِ العلاقاتِ الذي تستعملُه
     * {@see SubjectResource}، لأنّ اسمَ العلاقةِ واحدٌ على النموذجَين.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            TeacherProfilesRelationManager::class,
        ];
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
