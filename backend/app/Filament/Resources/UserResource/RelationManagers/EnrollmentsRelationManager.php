<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\EnrollmentResource;
use App\Filament\Resources\WorkspaceResource;
use App\Filament\Support\RecordLink;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
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
 * الكورساتُ التي سُجِّلَ فيها هذا الحسابُ طالباً — قراءةً فقط.
 *
 * ⚠️ **`student_user_id` لا `user_id`.** عمودُ الطالبِ على `enrollments`
 * اسمُه هذا، وعلاقةٌ بالافتراضِ تبحثُ عن عمودٍ لا وجودَ له.
 *
 * ⚠️ **وبلا نطاق**، للسببِ نفسِه في {@see OrdersRelationManager}.
 * `Enrollment::course()` تحملُ التجاوزَ و`withTrashed()` بنفسِها، و`workspace`
 * منصّيٌّ بلا نطاق.
 *
 * ولا إجراء: الحالةُ تتغيّرُ من شاشةِ التسجيلِ عبرَ `ChangeEnrollmentStatus`.
 */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $modelLabel = 'تسجيل';

    protected static ?string $pluralModelLabel = 'التسجيلات';

    protected static ?string $title = 'التسجيلات';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUserGroup;

    /** @return HasMany<Enrollment, Model> */
    public function getRelationship(): HasMany
    {
        return $this->getOwnerRecord()
            ->hasMany(Enrollment::class, 'student_user_id')
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
            ->defaultSort('enrolled_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->with(['course', 'workspace']))
            ->columns([
                TextColumn::make('course.title')
                    ->label('الكورس')
                    ->placeholder('—')
                    ->wrap()
                    ->url(fn (Enrollment $record): ?string => RecordLink::to(CourseResource::class, $record->course)),
                TextColumn::make('workspace.name')
                    ->label('المدرّس')
                    ->placeholder('—')
                    ->url(fn (Enrollment $record): ?string => RecordLink::to(WorkspaceResource::class, $record->workspace)),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EnrollmentStatus::labelFor($state)),
                TextColumn::make('progress_pct')
                    ->label('التقدّم')
                    ->formatStateUsing(fn (mixed $state): string => (string) ((int) $state).'٪'),
                TextColumn::make('enrolled_at')->label('تاريخ التسجيل')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordUrl(fn (Enrollment $record): ?string => RecordLink::to(EnrollmentResource::class, $record));
    }
}
