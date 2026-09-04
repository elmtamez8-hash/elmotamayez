<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ExamResource\Pages;
use App\Modules\Assessments\Enums\ExamStatus;
use App\Modules\Assessments\Models\Exam;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'المحتوى والتعلّم';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return 'الاختبارات';
    }

    public static function getModelLabel(): string
    {
        return 'اختبار';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الاختبارات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التعريف')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('العنوان')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('status')
                            ->label('الحالة')
                            ->options(ExamStatus::options())
                            ->required(),
                        Select::make('course_id')
                            ->label('الكورس')
                            ->relationship('course', 'title')
                            ->searchable()
                            ->preload(),
                    ]),

                Section::make('قواعد الأداء')
                    ->description('تُطبَّقُ في الإجراءِ الذي يبدأُ المحاولةَ ويُصحِّحُها، لا في هذهِ الشاشةِ وحدَها.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('duration_minutes')
                            ->label('المدّة')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('دقيقة')
                            ->default(60),
                        TextInput::make('passing_score')
                            ->label('درجة النجاح')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('٪')
                            ->default(60),
                        TextInput::make('max_attempts')
                            ->label('أقصى عدد محاولات')
                            ->numeric()
                            ->minValue(1)
                            ->default(3),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->sortable()->wrap(),
                TextColumn::make('course.title')
                    ->label('الكورس')
                    ->placeholder('—')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('course.workspace.name')
                    ->label('المدرّس')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => ExamStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'gray',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('duration_minutes')->label('المدّة')->suffix(' دقيقة'),
                TextColumn::make('passing_score')->label('درجة النجاح')->suffix('٪'),
                /*
                | العدّانِ من `withCount` لا من إغلاقٍ داخلَ العمود — المورِدُ يُنفَّذُ
                | مرّةً لكلِّ صفّ. و«أقصى عدد محاولات» مكتوبٌ بطولِه عمداً: عمودان
                | باسمِ «المحاولات» في جدولٍ واحدٍ، أحدُهما ما جرى والآخرُ السقفُ
                | المسموح، يُقرآنِ خطأً مرّةً على الأقلّ.
                */
                TextColumn::make('questions_count')
                    ->label('الأسئلة')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('attempts_count')
                    ->label('المحاولات')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('max_attempts')
                    ->label('أقصى عدد محاولات')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('أُنشئ في')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(ExamStatus::options()),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['course.workspace'])
            ->withCount(['questions', 'attempts']);
    }

    public static function getRelations(): array
    {
        return [
            ExamResource\RelationManagers\AttemptsRelationManager::class,
            ExamResource\RelationManagers\QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExams::route('/'),
            'edit' => Pages\EditExam::route('/{record}/edit'),
        ];
    }
}
