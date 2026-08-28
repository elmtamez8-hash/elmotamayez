<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\RelationManagers;

use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * من سجّل في هذا المقرّر — قراءةً فقط.
 *
 * ⚠️ بلا أيِّ إجراءِ إنشاءٍ أو حذف. إجراءُ Filament يكتبُ على العلاقةِ مباشرةً،
 * فيتجاوزُ `EnrollmentCreated` — وبتجاوزِه لا إشعارَ للطالبِ ولا تقدُّمَ مُهيَّأً
 * ولا شهادةَ لاحقاً. التسجيلُ يُنشَأُ من اعتمادِ الطلبِ وحدَه.
 */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    /*
    | ⚠️ `$title` يُسمّي التبويب، وما دونَه يقرأُ `$modelLabel` — وافتراضُه
    | **اسمُ العلاقةِ نفسُه**، فكانت حالةُ الفراغِ تقولُ «لا يوجد enrollments» تحتَ
    | تبويبٍ عربيّ.
    */
    protected static ?string $modelLabel = 'تسجيل';

    protected static ?string $pluralModelLabel = 'التسجيلات';

    protected static ?string $title = 'المسجَّلون';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUserGroup;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('enrolled_at', 'desc')
            ->columns([
                TextColumn::make('student.email')
                    ->label('الطالب')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => EnrollmentStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'completed' => 'info',
                        'expired' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('progress_pct')
                    ->label('التقدّم')
                    ->formatStateUsing(fn (mixed $state): string => (string) ((int) $state).'٪')
                    ->sortable(),
                TextColumn::make('enrolled_at')
                    ->label('تاريخ التسجيل')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordUrl(fn (Enrollment $record): string => route('filament.admin.resources.enrollments.edit', $record));
    }
}
