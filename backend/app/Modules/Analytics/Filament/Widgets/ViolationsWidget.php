<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Marketplace\Models\Complaint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * المخالفات — الشكاوى المرفوعةُ على المدرّسين.
 *
 * ⚠️ **الشكوى هي المخالفةُ ذاتُ الأثر**: هي وحدَها تحملُ حالةً ومؤكِّداً وتاريخَ
 * تأكيد، وهي التي تنزلُ في `RecalculateTrustScore`. إجراءاتُ الإشرافِ على
 * الرسائلِ (`moderation_actions`) عدَدٌ في {@see TrustPulseWidget} لا جدولٌ هنا:
 * صفُّها يحملُ نصَّ رسالةٍ في محادثةٍ خاصّةٍ بينَ طالبٍ ومدرّسِه، وعرضُه على
 * لوحةِ المنصّةِ يُسلِّمُ كلَّ نقاشٍ في المنتَجِ لمن يفتحُ صفحةَ إحصاءات.
 *
 * ⚠️ **و`withoutWorkspaceScope()` على الإسنادِ كذلك لا على الجذرِ وحدَه**:
 * استعلامُ العلاقةِ يُشغِّلُ نطاقاتِ نموذجِه، فمدرّسٌ خارجَ مساحةِ القارئِ يعودُ
 * `null` ويُقرَأُ الصفُّ شكوى بلا مشكوٍّ عليه.
 */
class ViolationsWidget extends BaseWidget
{
    use PlatformWideWidget;

    protected static ?int $sort = 9;

    protected static ?string $heading = 'المخالفات';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->complaints())
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('لا مخالفات مسجَّلة')
            ->emptyStateDescription('لم تُرفَعْ شكوى على أيّ مدرّس حتّى الآن.')
            ->columns([
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('teacherProfile.user.name')->label('المدرّس')->placeholder('—'),
                TextColumn::make('reporter.name')->label('مقدّم الشكوى')->placeholder('—')->toggleable(),
                TextColumn::make('reason')->label('السبب')->wrap()->limit(120),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => match ((string) $state) {
                        'confirmed' => 'مؤكَّدة',
                        'dismissed' => 'مرفوضة',
                        default => 'قيد البتّ',
                    })
                    ->color(fn (mixed $state): string => match ((string) $state) {
                        'confirmed' => 'danger',
                        'dismissed' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(['pending' => 'قيد البتّ', 'confirmed' => 'مؤكَّدة', 'dismissed' => 'مرفوضة']),
            ]);
    }

    /** @return Builder<Complaint> */
    private function complaints(): Builder
    {
        return Complaint::query()
            ->withoutWorkspaceScope()
            ->with([
                'teacherProfile' => fn ($query) => $query->withoutWorkspaceScope()
                    ->with('user:id,uuid,first_name,last_name'),
                'reporter:id,uuid,first_name,last_name',
            ]);
    }
}
