<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared half of the two taxonomy screens (spec 009 · Q8).
 *
 * Subjects and grade levels carry the SAME five columns and the same rules, so
 * the form and the table are written once — the reasoning {@see TaxonomyPolicy}
 * gives for serving both models: two files differing only in a type-hint is two
 * places for the next person to change one.
 *
 * ⚠️ NO TENANT ROLE REACHES EITHER SCREEN, THE WORKSPACE OWNER INCLUDED. Since
 * 009 there is one "الرياضيات" for the whole platform; a teacher editing this row
 * edits it for every teacher on the site. Authorisation is the policy, so this
 * screen and any future endpoint answer with the same code.
 */
abstract class TaxonomyResource extends Resource
{
    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')
                ->label('الاسم')
                ->required()
                ->maxLength(255)
                ->helperText('ما يقرأه الزائر والطالب. تغييرُه آمن: لا شيء يشير إلى الاسم.'),

            /*
            | ⚠️ IMMUTABLE ONCE WRITTEN, and this is the single most important line
            | in the file. The slug is a DENORMALISED JOIN KEY in three places and
            | none of them is a foreign key the database would defend:
            | `courses.grade_level` is text, `student_profiles.grade_level_slug` is
            | text, and every stored `grade:{slug}` leaderboard key is text.
            | Editing it splits one board into two and files a course under a stage
            | the marketplace can no longer name — silently, with nothing failing.
            | Same family as the media provider's title prefix, which orphans every
            | asset not yet recovered the moment it changes.
            */
            TextInput::make('slug')
                ->label('المُعرِّف')
                ->required()
                ->maxLength(255)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->disabledOn('edit')
                ->helperText('يُكتب مرّةً ولا يُعدَّل: الكورساتُ والطلابُ ولوحاتُ الصدارة تشير إليه نصّاً.'),

            TextInput::make('icon')
                ->label('الأيقونة')
                ->maxLength(255),

            TextInput::make('sort_order')
                ->label('الترتيب')
                ->numeric()
                ->default(0)
                ->required()
                ->helperText('ترتيبُ العرض في السوق العامّ، من الأصغر إلى الأكبر.'),

            Toggle::make('is_active')
                ->label('مفعَّل')
                ->default(true)
                ->helperText('إيقافُه يُخفيه من السوق ولا يمسّ كورساً ولا طالباً يشير إليه.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('slug')->label('المُعرِّف')->searchable(),
                TextColumn::make('sort_order')->label('الترتيب')->sortable(),
                IconColumn::make('is_active')->label('مفعَّل')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('مفعَّل'),
            ]);
    }

    /**
     * Retire, never erase — {@see TaxonomyPolicy::delete()} for why.
     *
     * The policy is the guard; these four are what stops Filament from RENDERING
     * a button that would then be refused, which reads as a broken panel rather
     * than as a rule.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
