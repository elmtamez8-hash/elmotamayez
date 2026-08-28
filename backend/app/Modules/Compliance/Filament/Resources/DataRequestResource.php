<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources;

use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Filament\Resources\DataRequestResource\Pages;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * طابورُ طلباتِ الحقوق: من طلبَ الاطّلاعَ أو التصديرَ أو الحذف، ومتى ينتهي الأجل.
 *
 * ⚠️ الشاشةُ للقراءةِ وحدَها، ولا يُضافُ إليها نموذجٌ ولا زرُّ تنفيذ. تنفيذُ الطلبِ
 * فعلٌ له حرّاسُه وأحداثُه وحمايتُه من التكرار — `FulfilDataRequestJob` وما تحته —
 * ونموذجُ لوحةٍ يكتبُ هذه الأعمدةَ مباشرةً هو بابٌ ثانٍ إلى قرارٍ يملكُه ذلك الفعل.
 * وفي حالةِ الحذفِ تحديداً يكونُ البابُ الثاني كارثةً هادئة: يُوسَمُ الطلبُ «مكتملاً»
 * ولم يُحذَفْ شيء.
 *
 * ⚠️ ولا عمودَ لِما لا يُتَّخَذُ عليه قرار. `export_path` و`granted_scope` غائبان:
 * الأوّلُ مسارٌ إلى أرشيفٍ يحملُ كلَّ ما تعرفُه المنصّةُ عن إنسانٍ واحد، والثاني
 * يُفشي للقارئِ أيَّ الفئاتِ يحقُّ لوليِّ الأمرِ الاطّلاعُ عليها. المُشغِّلُ يحتاجُ
 * الاسمَ والنوعَ والحالةَ والأجل، لا مضمونَ الطلب.
 *
 * ⚠️ ولا يحملُ هذا الجدولُ `workspace_id` ولا يجوزُ أن يحملَه: الطلبُ الواحدُ يغطّي
 * بياناتِ صاحبِه عند كلِّ مدرّسٍ يدرسُ عنده. فلا نطاقَ عامّاً يُلتَفُّ عليه هنا —
 * انظر `getEloquentQuery()` أدناه.
 */
class DataRequestResource extends Resource
{
    protected static ?string $model = DataRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'الامتثال';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'طلبات الحقوق';
    }

    public static function getModelLabel(): string
    {
        return 'طلب حقوق';
    }

    public static function getPluralModelLabel(): string
    {
        return 'طلبات الحقوق';
    }

    /**
     * ⚠️ بابٌ صريحٌ، لأنَّ افتراضَ Filament هو السماح.
     *
     * والقائمةُ لا تستدعي سياسةَ الصفِّ أبداً، فشاشةٌ بلا `canViewAny()` هي شاشةٌ
     * بلا حارس — وهذه الشاشةُ تعرضُ أسماءَ أشخاصٍ بأعيانِهم.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::COMPLIANCE_REQUESTS_EXECUTE) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            // الأجلُ النظاميُّ هو ساعةُ هذه الشاشة، والفهرسُ `(status, due_at)`
            // كُتبَ في الهجرةِ لهذا السؤالِ بعينِه.
            ->defaultSort('due_at', 'asc')
            ->columns([
                // العمودُ مفتاحٌ حقيقيٌّ ليجدَ البحثُ ما يقرأ، والمعروضُ هو الاسمُ
                // الكامل: `users` لا تحملُ عمودَ `name`، وإنّما هو قارئٌ فوق
                // `first_name` و`last_name`.
                TextColumn::make('subject.first_name')
                    ->label('صاحب البيانات')
                    ->formatStateUsing(fn (DataRequest $record): string => $record->subject->name ?? '—')
                    ->searchable(['first_name', 'last_name']),

                /*
                | مَن قدَّمَ الطلب، بلا اسمٍ ثانٍ على الشاشة. المقارنةُ بين العمودين
                | تكفي للتفريقِ بين صاحبِ البيانات ووليِّ أمرِه، وهي لا تُحمِّلُ
                | علاقةً ولا تكشفُ شخصاً ثانياً لا يحتاجُ المُشغِّلُ اسمَه.
                */
                TextColumn::make('requested_by_user_id')
                    ->label('مُقدِّم الطلب')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(fn (DataRequest $record): string => $record->requested_by_user_id === $record->subject_user_id
                        ? 'صاحب البيانات'
                        : 'نيابةً عنه'),

                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (DataRequestType $state): string => $state->label()),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (DataRequestStatus $state): string => $state->label())
                    ->color(fn (DataRequestStatus $state): string => match ($state) {
                        DataRequestStatus::Completed => 'success',
                        DataRequestStatus::Refused => 'danger',
                        DataRequestStatus::OnHold => 'warning',
                        DataRequestStatus::Processing => 'info',
                        DataRequestStatus::Pending => 'gray',
                    }),

                TextColumn::make('due_at')
                    ->label('الأجل النظاميّ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    // «متأخّر» يُقرأُ من `isOpen()` نفسِها لا من قائمةٍ ثانيةٍ من
                    // الحالات: الطلبُ المغلقُ لا يتأخّرُ عن شيء.
                    ->color(fn (DataRequest $record): string => $record->status->isOpen() && $record->due_at->isPast()
                        ? 'danger'
                        : 'gray'),

                TextColumn::make('last_attempt_at')
                    ->label('آخر محاولة')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('completed_at')
                    ->label('اكتملَ في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),

                // سببُ الرفضِ نصٌّ يكتبُه مُشغِّلٌ لمُشغِّل، ومحدودُ الطولِ هنا
                // لأنَّه يُقرأُ في الطابورِ لا يُحرَّرُ فيه.
                TextColumn::make('refusal_reason')
                    ->label('سبب الرفض')
                    ->placeholder('—')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('قُدِّمَ في')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(
                    collect(DataRequestStatus::cases())
                        ->mapWithKeys(fn (DataRequestStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),
                SelectFilter::make('type')->label('النوع')->options(
                    collect(DataRequestType::cases())
                        ->mapWithKeys(fn (DataRequestType $type): array => [$type->value => $type->label()])
                        ->all(),
                ),
                Filter::make('overdue')
                    ->label('تجاوزَ الأجل')
                    /**
                     * @param  Builder<DataRequest>  $query
                     */
                    ->query(function (Builder $query): void {
                        // قائمةُ الحالاتِ المفتوحةِ مشتقّةٌ من `isOpen()` ولا
                        // تُكتَبُ ثانيةً: إملاءان لسؤالٍ واحدٍ يفترقان عند أوّلِ
                        // حالةٍ تُضاف.
                        $query
                            ->whereIn('status', self::openStatuses())
                            ->where('due_at', '<', now());
                    }),
            ]);
    }

    /**
     * ⚠️ قراءةٌ على مستوى المنصّةِ كلِّها، ولا التفافَ مطلوبٌ هنا.
     *
     * `DataRequest` لا يستعملُ `BelongsToWorkspace` — بحكمِ التصميمِ لا سهواً — ولا
     * كذلك `User` خلفَ علاقةِ `subject`، فليس على هذا الاستعلامِ نطاقٌ عامٌّ
     * يُلتَفُّ عليه في الجذرِ ولا داخلَ التحميلِ المسبق.
     *
     * والتحميلُ المسبقُ غيرُ مقيَّدِ الأعمدةِ عن قصد: `name` قارئٌ فوق `first_name`
     * و`last_name`، وتحميلٌ مقيَّدٌ لا يسمّيهما يعرضُ كلَّ صفٍّ فارغاً بمئتينِ ولا
     * خطأَ في أيِّ مكان.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('subject');
    }

    /**
     * الحالاتُ التي ما زالَ الطلبُ فيها مفتوحاً.
     *
     * @return list<string>
     */
    private static function openStatuses(): array
    {
        return array_values(array_map(
            fn (DataRequestStatus $status): string => $status->value,
            array_filter(
                DataRequestStatus::cases(),
                fn (DataRequestStatus $status): bool => $status->isOpen(),
            ),
        ));
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDataRequests::route('/'),
        ];
    }
}
