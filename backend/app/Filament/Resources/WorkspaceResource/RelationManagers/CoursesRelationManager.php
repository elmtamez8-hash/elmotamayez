<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkspaceResource\RelationManagers;

use App\Filament\Resources\CourseResource;
use App\Filament\Support\RecordLink;
use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Models\Course;
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
 * كورساتُ مكانِ العمل — قراءةً فقط، وكلُّ صفٍّ يفتحُ كورسَه.
 *
 * ⚠️ **العلاقةُ تُبنى هنا لا على النموذج.** `Workspace` في وحدةِ `Tenancy`،
 * و`Course` في وحدةِ `Courses`؛ علاقةٌ على النموذجِ تجعلُ `Tenancy` تسمّي وحدةً
 * أخرى لأجلِ شاشةٍ واحدة. اللوحةُ خارجَ الوحدات، فالربطُ مكانُه هنا.
 *
 * ⚠️ **وبلا نطاقٍ في موضعَين.** سياقُ مديرِ المنصّةِ يرجعُ إلى
 * `users.last_workspace_id`، ونطاقُ `Course` كانَ سيضيفُ مكانَه هو إلى
 * `workspace_id = …` فتخرجُ القائمةُ فارغةً تحتَ مكانٍ غيرِ مكانِه، بلا خطأ.
 * و`withCount('enrollments')` استعلامٌ فرعيٌّ يسري عليه نطاقُ `Enrollment` من جديد.
 */
class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'courses';

    protected static ?string $modelLabel = 'كورس';

    protected static ?string $pluralModelLabel = 'الكورسات';

    protected static ?string $title = 'الكورسات';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedAcademicCap;

    /** @return HasMany<Course, Model> */
    public function getRelationship(): HasMany
    {
        return $this->getOwnerRecord()
            ->hasMany(Course::class, 'workspace_id')
            ->withoutGlobalScope(WorkspaceScope::class);
    }

    /** الصفحةُ الحاويةُ لمديرِ المنصّةِ وحدَه، والقائمةُ كذلك — لا سياسةُ `viewAny` لكورس. */
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
                    'enrollments' => fn (Builder $enrollments): Builder => $enrollments->withoutGlobalScope(WorkspaceScope::class),
                ]))
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->wrap(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => CourseStatus::labelFor($state)),
                TextColumn::make('enrollments_count')->label('المسجَّلون')->badge()->color('gray'),
                TextColumn::make('created_at')->label('أُنشئ في')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordUrl(fn (Course $record): ?string => RecordLink::to(CourseResource::class, $record));
    }
}
