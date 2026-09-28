<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\NavigationGroups;
use App\Filament\Resources\ExamResource\Pages;
use App\Filament\Support\RecordLink;
use App\Modules\Assessments\Enums\ExamStatus;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroups::CONTENT;

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
                        /*
                        | ⚠️ كورساتُ **مساحةِ الاختبار**، لا مساحةِ من يقرأُ الشاشة.
                        | `->relationship('course')` كان يبني القائمةَ تحتَ نطاقِ
                        | القارئ: مديرُ منصّةٍ له `last_workspace_id` يرى كورساتِ
                        | مساحتِه هو، فيربطُ ورقةَ مدرّسٍ بكورسِ مدرّسٍ آخر. و`in()`
                        | يُكرِّرُ الشرطَ على الطلبِ نفسِه — قائمةٌ مُرشَّحةٌ تُشكِّلُ
                        | الطلبَ الذي رسمَته لا الذي يليه.
                        */
                        Select::make('course_id')
                            ->label('الكورس')
                            ->options(fn (?Exam $record): array => self::coursesOf($record))
                            ->in(fn (?Exam $record): array => array_keys(self::coursesOf($record)))
                            ->searchable(),
                    ]),

                Section::make('قواعد الأداء')
                    ->description('تُطبَّقُ في الإجراءِ الذي يبدأُ المحاولةَ ويُصحِّحُها، لا في هذهِ الشاشةِ وحدَها.')
                    ->columns(3)
                    ->schema([
                        // ⚠️ حدودُ الأعمدة (`unsignedSmallInteger` / `unsignedTinyInteger`)
                        // لا ما يحتملُه SQLite: فراغٌ أو كسرٌ أو ٧٠٠٠٠ كانت تمرُّ هنا
                        // وتُرفَضُ على MySQL صفحةَ خطأ.
                        TextInput::make('duration_minutes')
                            ->label('المدّة')
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->suffix('دقيقة')
                            ->default(60),
                        TextInput::make('passing_score')
                            ->label('درجة النجاح')
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('٪')
                            ->default(60),
                        TextInput::make('max_attempts')
                            ->label('أقصى عدد محاولات')
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->maxValue(65535)
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
                // Not `searchable()`: a search on a relation column is a
                // `whereHas('course')`, which re-applies the `Course` scope and
                // finds only the reader's own workspace's courses.
                TextColumn::make('course.title')
                    ->label('الكورس')
                    ->placeholder('—')
                    ->url(fn (Exam $record): ?string => RecordLink::to(CourseResource::class, $record->course))
                    ->toggleable(),
                TextColumn::make('course.workspace.name')
                    ->label('المدرّس')
                    ->placeholder('—')
                    ->url(fn (Exam $record): ?string => RecordLink::to(WorkspaceResource::class, $record->course?->workspace))
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
            ]);
        /*
        | ⛔ NO BULK DELETE. `DeleteBulkAction` asks Filament's `deleteAny` and
        | nothing per row, so it was the one door onto an exam that no refusal
        | reached. A paper is deleted one at a time from its edit page, where
        | `Exam::deletionRefusal()` is shown before anything happens.
        */
    }

    /**
     * ⚠️ THE SUPER ADMIN ONLY — AND NO `Permissions::` CONSTANT CAN SAY SO.
     *
     * This list is platform-wide (below). Every exam permission (`exams.view`
     * and the rest) is a TENANT permission, and a finance or compliance officer
     * who also owns a workspace holds it there through their `tenant-owner` row
     * — so gating a platform-wide list on it hands that officer every teacher's
     * papers. No platform role holds an exam permission by the matrix, which
     * leaves the super admin as the only reader this screen has.
     *
     * `ExamPolicy::viewAny()` stays `allow`: it answers the API's question
     * («any member lists their own workspace's exams»), and the panel asks a
     * different one.
     */
    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * ⚠️ PLATFORM-WIDE, AND THE BYPASS IS REPEATED IN EVERY EAGER LOAD AND COUNT.
     *
     * With the scope left on, a super admin who has a `last_workspace_id` saw
     * that one workspace's exams and nothing else — no error, just a short list
     * that reads as a quiet week (024's fifth layer). Dropping it from the root
     * frees the outer read only: `with('course')` and `withCount('questions')`
     * are second queries that the `Course` and `Question` scopes apply to all
     * over again, so another workspace's paper showed a blank course and zero
     * questions. The record the edit page opens resolves through this query too.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        $unscoped = fn (Relation $relation): Relation => $relation->withoutGlobalScope(WorkspaceScope::class);

        return parent::getEloquentQuery()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->with([
                'course' => $unscoped,
                'course.workspace',
            ])
            ->withCount([
                'questions' => fn (Builder $query): Builder => $query->withoutGlobalScope(WorkspaceScope::class),
                'attempts' => fn (Builder $query): Builder => $query->withoutGlobalScope(WorkspaceScope::class),
            ]);
    }

    /**
     * The courses of the exam's own workspace, keyed by id.
     *
     * @return array<int, string>
     */
    private static function coursesOf(?Exam $exam): array
    {
        if (! $exam instanceof Exam) {
            return [];
        }

        /** @var array<int, string> $courses */
        // withTrashed: an exam whose course was soft-deleted must still save.
        $courses = Course::query()
            ->withTrashed()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $exam->workspace_id)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();

        return $courses;
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
