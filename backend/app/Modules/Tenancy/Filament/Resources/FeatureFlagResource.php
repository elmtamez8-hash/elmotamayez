<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources;

use App\Modules\Assessments\Actions\CreateStudyRoom;
use App\Modules\Assessments\Actions\StartAdaptiveSession;
use App\Modules\Tenancy\Filament\Resources\FeatureFlagResource\Pages;
use App\Modules\Tenancy\Models\FeatureFlag;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Flags;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Feature switches (spec 011 · FR-047 · FR-048).
 *
 * ⚠️ THE DOOR IS {@see FeatureFlagPolicy}, i.e. `flags.manage`, which no tenant
 * role holds. Turning a feature on for one teacher is a platform release
 * decision, not a workspace setting.
 *
 * ⚠️ THE WORKSPACE FIELD IS A SELECT WITH AN EXPLICIT «الافتراض العامّ» OPTION,
 * never a free number. `workspace_id = 0` is the sentinel that answers for the
 * whole platform, and `(int) null === 0` — so a field that could arrive empty
 * would turn a feature off everywhere from a screen aimed at one workspace. The
 * option is offered deliberately and labelled as what it is.
 *
 * ⚠️ AND THE KEY IS IMMUTABLE ONCE WRITTEN. The code asks {@see Flags} for a
 * literal string; renaming the row makes the call fall through to «unknown key
 * ⇒ off», which is a feature disappearing with nothing failing anywhere.
 */
class FeatureFlagResource extends Resource
{
    protected static ?string $model = FeatureFlag::class;

    protected static ?string $recordTitleAttribute = 'key';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return 'مفاتيح المزايا';
    }

    public static function getModelLabel(): string
    {
        return 'مفتاح ميزة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مفاتيح المزايا';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المفتاح')
                ->description('صفٌّ للمنصّة كلّها، وصفٌّ لكلِّ مساحةِ عملٍ تُستثنى منه. المساحةُ تغلبُ الافتراضَ العامّ، والمفتاحُ الذي لا صفَّ له مطفأ.')
                ->columns(2)
                ->schema([
                    /*
                    | ⛔ قائمةٌ من المفاتيحِ التي يسألُ عنها الكودُ فعلاً، لا نصٌّ حرّ:
                    | مفتاحٌ بخطأِ حرفٍ واحدٍ صفٌّ لا يقرؤه أحد، والميزةُ مطفأةٌ
                    | و«تم الحفظ» على الشاشة. انظرْ {@see self::knownKeys()}.
                    */
                    Select::make('key')
                        ->label('الميزة')
                        ->options(fn (?FeatureFlag $record): array => self::keyOptions($record?->key))
                        ->required()
                        ->disabledOn('edit')
                        ->helperText('يُكتب مرّةً ولا يُعدَّل: الكودُ يسألُ عنه نصّاً، وتغييرُه يُطفئ الميزةَ بلا خطأٍ في أيِّ مكان.'),

                    Select::make('workspace_id')
                        ->label('النطاق')
                        ->options(fn (): array => [
                            Flags::PLATFORM => 'الافتراض العامّ (كل المنصّة)',
                            ...Workspace::query()->orderBy('name')->pluck('name', 'id')->all(),
                        ])
                        ->default(Flags::PLATFORM)
                        ->required()
                        ->searchable()
                        ->helperText('«الافتراض العامّ» يسري على كلِّ مساحةٍ لا صفَّ لها هنا.'),

                    Toggle::make('enabled')
                        ->label('مشتعل')
                        ->helperText('يسري فوراً: القراءةُ مُذكَّرةٌ لطلبٍ واحدٍ فقط.'),

                    Textarea::make('description')
                        ->label('ماذا يفعل')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText('مفتاحٌ معناه في رسالةِ commit هو مفتاحٌ لا يجرؤ أحدٌ على إطفائه.'),
                ]),
        ]);
    }

    /**
     * Every key the code asks `Flags::enabled()` about, with what it switches.
     *
     * ⚠️ READ FROM THE CONSTANTS THE ASKING ACTIONS DECLARE, never retyped: a key
     * spelled here and there separately is exactly the silent mismatch this
     * picker exists to prevent. A new flag is one line here beside its `FLAG`.
     *
     * @return array<string, string>
     */
    public static function knownKeys(): array
    {
        return [
            CreateStudyRoom::FLAG => 'غرف المذاكرة',
            StartAdaptiveSession::FLAG => 'التدريب التكيّفي',
        ];
    }

    /**
     * The picker's options — plus the stored key when the code no longer knows it,
     * so an old row still reads its own name on the edit screen.
     *
     * @return array<string, string>
     */
    private static function keyOptions(?string $current): array
    {
        $options = self::knownKeys();

        if ($current !== null && $current !== '' && ! isset($options[$current])) {
            $options[$current] = $current.' (لا يسأل عنه الكود)';
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label('الميزة')->searchable()->sortable()
                    ->formatStateUsing(fn (mixed $state): string => is_string($state) ? (self::knownKeys()[$state] ?? $state) : '—')
                    ->description(fn (FeatureFlag $record): string => $record->key),
                TextColumn::make('workspace_id')
                    ->label('النطاق')
                    ->formatStateUsing(fn (int $state): string => $state === Flags::PLATFORM
                        ? 'الافتراض العامّ'
                        : (string) (Workspace::query()->whereKey($state)->value('name') ?? $state)),
                IconColumn::make('enabled')->label('مشتعل')->boolean(),
                TextColumn::make('description')->label('ماذا يفعل')->wrap()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('enabled')->label('مشتعل'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeatureFlags::route('/'),
            'create' => Pages\CreateFeatureFlag::route('/create'),
            'edit' => Pages\EditFeatureFlag::route('/{record}/edit'),
        ];
    }
}
