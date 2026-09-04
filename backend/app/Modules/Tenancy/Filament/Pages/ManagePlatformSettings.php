<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Pages;

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\PlatformSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The operational dials, editable without a deploy.
 *
 * Platform-level, so the gate is `is_super_admin` and not a tenant permission:
 * the device limit governs a student account that enrols with many teachers, and
 * no single teacher may decide it.
 *
 * @property-read Schema $form
 */
class ManagePlatformSettings extends Page
{
    protected string $view = 'filament.pages.manage-platform-settings';

    protected static ?string $slug = 'platform-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات المنصّة';
    }

    public function getTitle(): string
    {
        return 'إعدادات المنصّة';
    }

    public function mount(BillingSettings $billing): void
    {
        /** @var array<string, int> $limits */
        $limits = PlatformSettings::get('auth.device_limits', []);

        $this->form->fill([
            'platform_name' => PlatformSettings::get('platform.name'),
            'student_device_limit' => $limits['student'] ?? 1,
            'two_factor_grace_days' => PlatformSettings::get('auth.two_factor_grace_days'),
            'max_size_bytes' => PlatformSettings::get('media.max_size_bytes'),
            'max_duration_seconds' => PlatformSettings::get('media.max_duration_seconds'),
            'grant_ttl_seconds' => PlatformSettings::get('media.grant_ttl_seconds'),
            'max_renewals' => PlatformSettings::get('media.max_renewals'),
            /*
            | ⚠️ يُقرَأُ من {@see BillingSettings} لا من `PlatformSettings::get()`
            | مباشرةً: تلك الطرقُ تحملُ الارتدادَ إلى `config/billing.php` وحدَّ
            | `max(0, …)`، و`stop_selling_after_days` **لا صفَّ له أصلاً** على
            | الإنتاج. قراءةٌ خامٌّ هنا تعرضُ خانةً فارغةً عن رقمٍ يعملُ فعلاً،
            | فيحفظُها المشغِّلُ صفراً ظانّاً أنّه لم يغيّرْ شيئاً.
            */
            'operating_fee_individual' => $billing->operatingFeeMinor(ClassSessionType::Individual),
            'operating_fee_group' => $billing->operatingFeeMinor(ClassSessionType::Group),
            'gateway_fee_bps' => $billing->gatewayFeeBps(),
            'gateway_fixed_fee_minor' => $billing->gatewayFixedFeeMinor(),
            'stop_selling_after_days' => $billing->stopSellingAfterDays(),
            'max_unredeemed_credits' => $billing->maxUnredeemedCredits(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    /*
                    | ⚠️ الاسمُ يُقرأُ وقتَ التشغيلِ من هذا الصفّ، لا من متغيّرِ
                    | بيئةٍ يُدمَجُ وقتَ البناء. الشكلُ الأوّلُ (`NEXT_PUBLIC_PLATFORM_NAME`)
                    | كانَ غيرَ مضبوطٍ على الخادمِ شهوراً، فقرأَ كلُّ عنوانٍ في
                    | الموقعِ الاسمَ البديلَ «منصّتي» بلا خطأٍ في أيِّ مكان.
                    */
                    Section::make('هويّة المنصّة')
                        ->description('يظهر في عنوان كل صفحة، وفي رأس اللوحة وتذييل الموقع. التغيير يسري بلا إعادة نشر.')
                        ->schema([
                            TextInput::make('platform_name')
                                ->label('اسم المنصّة')
                                ->helperText('الشعار يُرسَم من ملف العلامة، وهذا الاسم هو ما يُقرأ نصّاً — في عنوان التبويب ولقارئ الشاشة.')
                                ->maxLength(60)
                                ->required(),
                        ]),
                    Section::make('الأجهزة والحسابات')
                        ->description('عدد الأجهزة لا الجلسات: جلستان على الجهاز نفسه تبقيان معاً.')
                        ->schema([
                            TextInput::make('student_device_limit')
                                ->label('عدد الأجهزة النشطة لحساب الطالب')
                                ->helperText('الدخول من جهاز إضافي يُنهي جلسات أقدم جهاز تلقائياً.')
                                ->numeric()->minValue(1)->maxValue(10)->required(),
                            TextInput::make('two_factor_grace_days')
                                ->label('مهلة إلزام التحقق الثنائي (بالأيام)')
                                ->numeric()->minValue(0)->maxValue(365)->required(),
                        ]),
                    Section::make('الفيديو')
                        ->description('هذه هي الحدودُ المعلَنةُ للمزوّد والمفروضةُ عند الرفع معاً؛ رقمان مختلفان يعني وعداً يخالف ما يُقبَل.')
                        ->schema([
                            TextInput::make('max_size_bytes')
                                ->label('الحد الأقصى لحجم الملف (بايت)')
                                ->numeric()->minValue(1)->required(),
                            TextInput::make('max_duration_seconds')
                                ->label('الحد الأقصى لمدة الفيديو (ثانية)')
                                ->numeric()->minValue(1)->required(),
                            TextInput::make('grant_ttl_seconds')
                                ->label('عمر منحة التشغيل (ثانية)')
                                ->helperText('تُجدَّد تلقائياً أثناء المشاهدة؛ القيمة القصيرة تعني توقّفاً أسرع عند انتهاء الجلسة.')
                                ->numeric()->minValue(30)->required(),
                            TextInput::make('max_renewals')
                                ->label('أقصى عدد تجديدات لجلسة مشاهدة واحدة')
                                ->numeric()->minValue(1)->required(),
                        ]),
                    /*
                    | ⛔ **هذه الأرقامُ هي «حصّةُ المنصّة»، ولم تكنْ لها شاشةٌ قطّ.**
                    |
                    | `BillingPricingController` موجودٌ بمسارَيه
                    | (`GET`/`PUT /admin/billing/pricing`) وبصلاحيّةِ
                    | `billing.pricing.manage`، وتعليقُه يقولُ إنّ الحارسَينِ
                    | «يُداران من الشاشةِ نفسِها» — **ولا شاشةَ في المنتَجِ كلِّه
                    | تنادِيه**، لا في اللوحةِ ولا في الواجهة. فبقيَت الأربعةُ على
                    | أصفارِها المبذورة، وكلُّ بيعةٍ على المنصّةِ سُجِّلَت بحصّةٍ
                    | صفر: «إذنٌ لا يصلُه رابط» يرتدي مالاً.
                    |
                    | قِيسَ على الإنتاج 2026-09-04: أربعةُ صفوفٍ بقيمةِ `0`، وشراءُ
                    | الأرصدةِ الوحيدُ بـ`operating_fee_minor = 0` و
                    | `gateway_fee_minor = 0` على ‏٤٨٠ ر.ق.
                    |
                    | ⚠️ **وتغييرُها لا يُعيدُ تسعيرَ ما بيعَ سلفاً**: كلُّ شراءٍ
                    | يحملُ لقطتَه الرباعيّةَ تُكتَبُ مرّةً ولا تُحسَبُ ثانيةً
                    | (‏FR-021ح)، فالرقمُ الجديدُ للبيعةِ التالية.
                    */
                    Section::make('التسعير ورسوم المنصّة')
                        ->description('حصّةُ المنصّة من كلّ حصّة. تسري على ما يُباع بعد الحفظ — ولا تمسّ شراءً تمّ سلفاً.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('operating_fee_individual')
                                ->label('رسوم التشغيل — حصّة فرديّة (بالوحدات الصغرى)')
                                ->helperText('‏٥٫٠٠ ر.ق تُكتب 500')
                                ->numeric()->minValue(0)->maxValue(100000000)->required(),
                            /*
                            | ⚠️ رسمٌ للمجموعةِ على حدة: استضافةُ حصّةِ مجموعةٍ
                            | تكلِّفُ مرّةً لا مرّةً لكلِّ طالب، ورسمٌ واحدٌ
                            | يُضاعِفُ الهامشَ بصمتٍ على كلِّ حصّةٍ جماعيّة.
                            */
                            TextInput::make('operating_fee_group')
                                ->label('رسوم التشغيل — حصّة مجموعة (بالوحدات الصغرى)')
                                ->helperText('استضافةُ المجموعة تكلّف مرّةً لا مرّةً لكلّ طالب')
                                ->numeric()->minValue(0)->maxValue(100000000)->required(),
                            /*
                            | ⚠️ السقفُ ‏٩٩٩٩ لا ‏١٠٠٠٠: بوّابةٌ تأخذُ الدفعةَ كاملةً
                            | تجعلُ معادلةَ الرفعِ غيرَ قابلةٍ للحلّ، و
                            | `CostPlusPricing` يرمي بدلَ أن يُسعِّرَ برقمٍ خرجَ من
                            | قسمةٍ صارت سالبة.
                            */
                            TextInput::make('gateway_fee_bps')
                                ->label('نسبة بوابة الدفع (نقاط أساس)')
                                ->helperText('‏٢٫٥٪ تُكتب 250 — والحدّ الأقصى 9999')
                                ->numeric()->minValue(0)->maxValue(9999)->required(),
                            TextInput::make('gateway_fixed_fee_minor')
                                ->label('الرسم الثابت للبوّابة (بالوحدات الصغرى)')
                                ->numeric()->minValue(0)->maxValue(100000000)->required(),
                            TextInput::make('stop_selling_after_days')
                                ->label('إيقاف البيع بعد (يوماً)')
                                ->helperText('حارسُ الأمانة: رصيدٌ لم يُستهلَك بعد هذه المدّة يوقف بيع المزيد')
                                ->numeric()->minValue(1)->maxValue(3650)->required(),
                            TextInput::make('max_unredeemed_credits')
                                ->label('أقصى رصيد غير مستهلَك (حصص)')
                                ->numeric()->minValue(1)->maxValue(1000)->required(),
                        ]),
                    Actions::make([
                        Action::make('save')->label('حفظ')->submit('save'),
                    ]),
                ])->livewireSubmitHandler('save'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();
        $userId = Auth::id();
        $userId = is_int($userId) ? $userId : null;

        // ⚠️ `trim`، فاسمٌ بفراغٍ في طرفِه يظهرُ في `<title>` ولا يُرى في الحقل.
        PlatformSettings::set('platform.name', trim((string) $data['platform_name']), $userId);
        PlatformSettings::set('auth.device_limits', ['student' => (int) $data['student_device_limit']], $userId);
        PlatformSettings::set('auth.two_factor_grace_days', (int) $data['two_factor_grace_days'], $userId);
        PlatformSettings::set('media.max_size_bytes', (int) $data['max_size_bytes'], $userId);
        PlatformSettings::set('media.max_duration_seconds', (int) $data['max_duration_seconds'], $userId);
        PlatformSettings::set('media.grant_ttl_seconds', (int) $data['grant_ttl_seconds'], $userId);
        PlatformSettings::set('media.max_renewals', (int) $data['max_renewals'], $userId);

        // المفاتيحُ بنصِّها كما يقرؤها `BillingSettings` — هجاءٌ ثانٍ هنا يكتبُ
        // صفّاً لا يقرؤه أحدٌ وشاشةً تُظهِرُ ما لا يُسعِّرُ به المنتَج.
        PlatformSettings::set('billing.operating_fee_minor.individual', (int) $data['operating_fee_individual'], $userId);
        PlatformSettings::set('billing.operating_fee_minor.group', (int) $data['operating_fee_group'], $userId);
        PlatformSettings::set('billing.gateway_fee_bps', (int) $data['gateway_fee_bps'], $userId);
        PlatformSettings::set('billing.gateway_fixed_fee_minor', (int) $data['gateway_fixed_fee_minor'], $userId);
        PlatformSettings::set('billing.stop_selling_after_days', (int) $data['stop_selling_after_days'], $userId);
        PlatformSettings::set('billing.max_unredeemed_credits', (int) $data['max_unredeemed_credits'], $userId);

        Notification::make()->success()->title('حُفظت الإعدادات')->send();
    }
}
