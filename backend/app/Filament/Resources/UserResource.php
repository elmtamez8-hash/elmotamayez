<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Modules\Identity\Filament\Pages\CreateAccount;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\UserStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * دفترُ حسابات المنصّة — **قراءةً فقط**، ولمدير المنصّة وحدَه.
 *
 * ⚠️ ولا تعديلَ ولا حذف، وليس تكاسُلاً: `users.status` بوّابةُ
 * دخولٍ يقرؤها مسارُ تسجيلِ الدخول، و`platform_role` يقرِّرُ أيَّ منتَجٍ يرى صاحبُ
 * الحساب، و`is_super_admin` هو صلاحيةُ المنصّةِ كلِّها. حقلٌ من هذهِ في نموذجٍ
 * عامٍّ هو بابٌ ثانٍ لقرارٍ تملكُه إجراءاتُ `Identity` وحدَها — ومحوُ حسابٍ من هنا
 * يتجاوزُ عقدَ `PersonalDataOwner` بأكملِه، فيتركُ صفوفاً يتيمةً في كلِّ وحدة.
 *
 * ⚠️ والإنشاءُ **موجودٌ** الآن، وهو ما يُبقي ما سبقَ صحيحاً بدلَ أن ينقضَه.
 * {@see CreateAccount} صفحةٌ لا تكتبُ عموداً
 * واحداً بنفسِها: تنادي `RegisterStudent` أو `RegisterParent` — الإجراءَ ذاتَه
 * الذي ينادِيه بابُ التسجيلِ العلنيّ — فلا حقلَ فيها لـ`platform_role` ولا
 * لـ`status` ولا لـ`is_super_admin`، والصفُّ الذي تُخرِجُه هو صفُّ ذلك الباب.
 * الاعتراضُ أعلاه على **حقولٍ في نموذجٍ عامّ**، لا على الإنشاء.
 *
 * ⚠️ والبابُ مُعلَنٌ صراحةً. `viewAny()` الافتراضيّةُ تسمحُ، وهذا المستودعُ دفعَ
 * ثمنَ ذلك مرّةً في `OrderResource`: قائمةُ Filament لا تستدعي سياسةَ الصفِّ أبداً،
 * فشاشةٌ بلا `canViewAny()` هي شاشةٌ بلا حارس.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getNavigationLabel(): string
    {
        return 'الحسابات';
    }

    public static function getModelLabel(): string
    {
        return 'حساب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الحسابات';
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
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
            ->defaultSort('created_at', 'desc')
            ->columns([
                /*
                | ⚠️ `name` سِمةٌ محسوبةٌ فوقَ `first_name` و`last_name` — لا عمودَ
                | بهذا الاسمِ في الجدول، فالبحثُ يُوجَّهُ إلى العمودَينِ الحقيقيَّين
                | أو لا يجدُ شيئاً أبداً.
                */
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name']),
                TextColumn::make('email')
                    ->label('البريد')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('نُسخ البريد'),
                TextColumn::make('platform_role')
                    ->label('الصفة')
                    ->badge()
                    ->placeholder('أكاديميّة')
                    ->formatStateUsing(fn (mixed $state): string => PlatformRole::labelFor(
                        $state instanceof PlatformRole ? $state->value : (is_scalar($state) ? (string) $state : null),
                    ))
                    ->color(fn (mixed $state): string => match ($state instanceof PlatformRole ? $state->value : (string) $state) {
                        'teacher' => 'success',
                        'parent' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => UserStatus::labelFor(is_scalar($state) ? (string) $state : null))
                    ->color(fn (mixed $state): string => (string) $state === UserStatus::Active->value ? 'success' : 'warning'),
                IconColumn::make('is_super_admin')
                    ->label('مدير منصّة')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('email_verified_at')
                    ->label('توثيق البريد')
                    ->dateTime('Y-m-d')
                    ->placeholder('غير موثَّق')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('تاريخ التسجيل')
                    ->dateTime('Y-m-d H:i')
                    ->description(fn (User $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('platform_role')
                    ->label('الصفة')
                    ->options(PlatformRole::options()),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(UserStatus::options()),
                TernaryFilter::make('is_super_admin')
                    ->label('مدير منصّة')
                    ->placeholder('الكلّ'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
        ];
    }
}
