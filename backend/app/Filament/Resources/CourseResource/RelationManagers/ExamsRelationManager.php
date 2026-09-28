<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\RelationManagers;

use App\Filament\Resources\ExamResource;
use App\Filament\Support\RecordLink;
use App\Modules\Assessments\Enums\ExamStatus;
use App\Modules\Assessments\Models\Exam;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * امتحاناتُ هذا الكورس — قراءةً فقط، وكلُّ صفٍّ يفتحُ امتحانَه.
 *
 * ⚠️ **العلاقةُ تُبنى هنا لا على `Course`**: وحدةُ `Courses` لا تسمّي
 * `Assessments`، والامتحانُ هو من يحملُ `course_id`.
 *
 * ⚠️ **وبلا نطاقٍ في الجذرِ وفي العدَّين** — الكورسُ في مكانٍ غيرِ مكانِ مديرِ
 * المنصّة، و`withCount` استعلامٌ فرعيٌّ يسري عليه نطاقُ النموذجِ المعدودِ من جديد.
 * القاعدةُ نفسُها في {@see ExamResource::getEloquentQuery()}.
 *
 * ولا حذفَ ولا إنشاء: حذفُ امتحانٍ عليه محاولاتٌ يرفضُه النموذج، والإنشاءُ من
 * شاشةِ المدرّس.
 */
class ExamsRelationManager extends RelationManager
{
    protected static string $relationship = 'exams';

    protected static ?string $modelLabel = 'امتحان';

    protected static ?string $pluralModelLabel = 'الامتحانات';

    protected static ?string $title = 'الامتحانات';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentCheck;

    /** @return HasMany<Exam, Model> */
    public function getRelationship(): HasMany
    {
        return $this->getOwnerRecord()
            ->hasMany(Exam::class, 'course_id')
            ->withoutGlobalScope(WorkspaceScope::class);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->withCount([
                    'questions' => fn (Builder $questions): Builder => $questions->withoutGlobalScope(WorkspaceScope::class),
                    'attempts' => fn (Builder $attempts): Builder => $attempts->withoutGlobalScope(WorkspaceScope::class),
                ]))
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->wrap(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => ExamStatus::labelFor($state)),
                TextColumn::make('questions_count')->label('الأسئلة')->badge()->color('gray'),
                TextColumn::make('attempts_count')->label('المحاولات')->badge()->color('gray'),
                TextColumn::make('created_at')->label('أُنشئ في')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordUrl(fn (Exam $record): ?string => RecordLink::to(ExamResource::class, $record));
    }
}
