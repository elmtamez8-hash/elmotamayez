<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Modules\Marketplace\Filament\Resources\SubjectResource\Pages;
use App\Modules\Marketplace\Models\Subject;
use Filament\Resources\Pages\PageRegistration;

/**
 * The platform's subjects — one "الرياضيات" for everybody.
 *
 * The form, the table and the delete refusal are {@see TaxonomyResource}'s; what
 * is here is the model and what to call it.
 */
class SubjectResource extends TaxonomyResource
{
    protected static ?string $model = Subject::class;

    public static function getNavigationLabel(): string
    {
        return 'المواد';
    }

    public static function getModelLabel(): string
    {
        return 'مادّة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المواد';
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubjects::route('/'),
            'create' => Pages\CreateSubject::route('/create'),
            'edit' => Pages\EditSubject::route('/{record}/edit'),
        ];
    }
}
