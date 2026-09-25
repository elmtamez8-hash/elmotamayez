<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources;

use App\Models\User;
use App\Modules\Compliance\Actions\ReleaseLegalHold;
use App\Modules\Compliance\Filament\Resources\LegalHoldResource\Pages;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * التعليقاتُ القانونيّة: مَن تُمنَعُ بياناتُه من الحذف، ولماذا، ومنذ متى.
 *
 * ⚠️ لا نموذجَ إنشاءٍ ولا تعديلٍ ولا حذفٍ هنا، وهذا باقٍ. وضعُ التعليقِ ورفعُه
 * فعلانِ لهما حرّاسُهما (`PlaceLegalHold` و`ReleaseLegalHold`)، ونموذجُ Filament
 * الافتراضيُّ يكتبُ الصفَّ بـ`new Model($data)` فيتخطّاهما — فلا يُعلِّقُ طلبَ حذفٍ
 * مفتوحاً، ولا يُعيدُه إلى الطابورِ عندَ الرفع.
 *
 * ⚠️ والزرّانِ الوحيدانِ هما «وضع تعليق» في رأسِ القائمة (`ListLegalHolds`) و«رفع
 * التعليق» على الصفّ، وكلٌّ منهما يستدعي الفعلَ نفسَه الذي يستدعيه
 * `POST|DELETE /manage/compliance/holds` — وقبلَهما لم يكن لهذين المسارَينِ مُستدعٍ
 * في أيِّ شاشة، فكانَ التعليقُ القانونيُّ ميزةً لا يملكُها أحد.
 *
 * ⚠️ وقيمةُ الشاشةِ في القراءةِ نفسِها: كنسُ الاحتفاظِ وتنفيذُ الحذفِ يقرآنِ هذا
 * الجدولَ في رأسِ كلِّ دفعة، فالمُشغِّلُ الذي يوشكُ أن ينفِّذَ طلبَ حذفٍ يحتاجُ أن
 * يرى ما هو معلَّقٌ قبلَه لا بعدَه.
 *
 * ⚠️ و«ساري» يُقرأُ من `inForce()` نفسِها لا من `whereNull` ثانيةٍ مكتوبةٍ هنا:
 * إملاءان لسؤالٍ واحدٍ يفترقان، وافتراقُهما هنا يعني حذفاً يمضي رغمَ تعليقٍ وضعتْه
 * محكمة.
 */
class LegalHoldResource extends Resource
{
    protected static ?string $model = LegalHold::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'الامتثال';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return 'التعليقات القانونيّة';
    }

    public static function getModelLabel(): string
    {
        return 'تعليق قانونيّ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'التعليقات القانونيّة';
    }

    /** بابٌ صريح: افتراضُ Filament هو السماح، والقائمةُ لا تسألُ سياسةَ الصفِّ أبداً. */
    public static function canViewAny(): bool
    {
        return self::canManage();
    }

    /**
     * صلاحيّةٌ واحدةٌ للاتّجاهَين، كما في `LegalHoldPolicy::manage()`.
     *
     * ⚠️ ويُسألُ عنها كلُّ زرٍّ صراحةً: القائمةُ لا تستدعي سياسةَ الصفِّ، و`visible()`
     * هو البابُ الوحيدُ بين الزرِّ ومَن يراه.
     */
    public static function canManage(): bool
    {
        return auth()->user()?->can(Permissions::COMPLIANCE_HOLDS_MANAGE) ?? false;
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
            ->defaultSort('placed_at', 'desc')
            ->columns([
                TextColumn::make('subject.first_name')
                    ->label('صاحب البيانات')
                    ->formatStateUsing(fn (LegalHold $record): string => $record->subject->name ?? '—')
                    ->searchable(['first_name', 'last_name']),

                // الحالةُ محسوبةٌ من `released_at` وحدَه: لا عمودَ `is_active` بجانبَه،
                // وجوابان لسؤالٍ واحدٍ هما ما يُبقي الحذفَ ماضياً رغمَ التعليق.
                TextColumn::make('hold_state')
                    ->label('الحالة')
                    ->badge()
                    ->getStateUsing(fn (LegalHold $record): string => $record->released_at === null ? 'ساري' : 'مرفوع')
                    ->color(fn (LegalHold $record): string => $record->released_at === null ? 'danger' : 'gray'),

                TextColumn::make('reason')
                    ->label('السبب')
                    ->wrap()
                    ->limit(80),

                TextColumn::make('placed_at')
                    ->label('وُضعَ في')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('released_at')
                    ->label('رُفعَ في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('in_force')
                    ->label('السارية وحدَها')
                    ->query(self::inForceOnly(...)),
            ])
            ->recordActions([
                /*
                | ⚠️ رفعٌ لا حذف، ويسألُ قبلَ أن يمضي. رفعُ آخرِ تعليقٍ عن شخصٍ يُعيدُ
                | طلبَ حذفِه الموقوفَ إلى الطابور، والحذفُ حين يُنفَّذُ لا يُعكَس —
                | فالتأكيدُ يقولُ ذلك بجملةٍ لا بلونِ الزرّ وحدَه.
                */
                Action::make('release')
                    ->label('رفع التعليق')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('رفع التعليق القانونيّ')
                    ->modalDescription('إن لم يبقَ على صاحب البيانات تعليقٌ آخر، يعود طلب حذفه الموقوف — إن وُجد — '
                        .'إلى طابور «طلبات حقوق البيانات» بانتظار من ينفّذه. ولا يُحذف صفّ التعليق: يبقى سجلّاً بمن رفعه ومتى.')
                    ->modalSubmitActionLabel('ارفع التعليق')
                    ->visible(fn (LegalHold $record): bool => $record->released_at === null && self::canManage())
                    ->action(function (LegalHold $record): void {
                        abort_unless(self::canManage(), 403);

                        /** @var User $officer */
                        $officer = auth()->user();

                        app(ReleaseLegalHold::class)->handle($record, $officer);
                    }),
            ]);
    }

    /**
     * التعليقاتُ السارية، مقروءةً من `inForce()` نفسِها.
     *
     * ⚠️ دالّةٌ مسمّاةٌ لا غلافاً مكتوباً داخلَ المُرشِّح، لأنَّ هذا هو الموضعُ الوحيدُ
     * الذي يقرأُ فيه المحلِّلُ نوعَ الاستعلامِ الدقيق (`Builder<LegalHold>`) فيعرفَ
     * أنَّ `inForce()` نطاقٌ على النموذج. وبدونِه لا يبقى إلّا إعادةُ كتابةِ
     * `whereNull` بيدٍ ثانية — إملاءان لسؤالٍ واحدٍ يفترقان، وافتراقُهما هنا حذفٌ
     * يمضي رغمَ تعليقٍ ساري.
     *
     * @param  Builder<LegalHold>  $query
     */
    private static function inForceOnly(Builder $query): void
    {
        $query->inForce();
    }

    /**
     * قراءةٌ على مستوى المنصّة، بلا التفافٍ لأنَّه لا نطاقَ هنا أصلاً.
     *
     * `LegalHold` لا يستعملُ `BelongsToWorkspace` — التعليقُ يخصُّ شخصاً في
     * المنصّةِ كلِّها لا في مساحةِ عملٍ واحدة — ولا كذلك `User` خلفَ `subject`، فليس
     * في الجذرِ ولا في التحميلِ المسبقِ نطاقٌ عامٌّ يُعادُ الالتفافُ عليه.
     *
     * والتحميلُ المسبقُ ليس زينة: العمودُ يقرأُ اسمَ صاحبِ البيانات، وعمودُ Filament
     * يُنفَّذُ مرّةً لكلِّ صفّ — فبدونَه استعلامٌ لكلِّ سطرٍ بحكمِ البناء. وهو غيرُ
     * مقيَّدِ الأعمدةِ عن قصد: `name` قارئٌ فوق `first_name` و`last_name`، وتحميلٌ
     * مقيَّدٌ لا يسمّيهما يعرضُ كلَّ صفٍّ فارغاً بمئتينِ ولا خطأَ في أيِّ مكان.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('subject');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegalHolds::route('/'),
        ];
    }
}
