<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Pages;

use App\Models\User;
use App\Modules\Compliance\Actions\SaveDataCategory;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\TransferInstructions;
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
            'support_whatsapp' => PlatformSettings::get('platform.support_whatsapp'),
            ...self::transferFormState(),
            'student_device_limit' => $limits['student'] ?? 1,
            'two_factor_grace_days' => PlatformSettings::get('auth.two_factor_grace_days'),
            /*
            | ⚠️ المدّةُ تُقرَأُ من صفِّ الفئةِ لا من `platform_settings`: شاشةُ
            | «خصوصيّتي» تقرؤُها من هناك وتطبعُها جملةً، فنسختانِ مخزَّنتانِ
            | تعنيانِ شاشةً تقولُ شيئاً ومكنسةً تفعلُ غيرَه.
            */
            'auth_session_retain_days' => DataCategory::query()->where('key', 'auth_session')->value('retain_days'),
            'auth_session_cap_per_user' => PlatformSettings::get('auth.auth_session_cap_per_user'),
            'auth_session_cap_min_age_days' => PlatformSettings::get('auth.auth_session_cap_min_age_days'),
            'session_idle_days' => PlatformSettings::get('auth.session_idle_days'),
            'max_size_bytes' => PlatformSettings::get('media.max_size_bytes'),
            'max_duration_seconds' => PlatformSettings::get('media.max_duration_seconds'),
            'grant_ttl_seconds' => PlatformSettings::get('media.grant_ttl_seconds'),
            'max_renewals' => PlatformSettings::get('media.max_renewals'),
            'watched_share' => PlatformSettings::get('media.watched_share'),
            'watched_fallback_seconds' => PlatformSettings::get('media.watched_fallback_seconds'),
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

    /**
     * @return array<string, string>
     */
    private static function transferFormState(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = PlatformSettings::get('billing.transfer', []);

        $state = [];

        foreach (TransferInstructions::FIELDS as $field) {
            $state['transfer_'.$field] = (string) ($stored[$field] ?? '');
        }

        return $state;
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
                        ->description('الاسم يظهر في عنوان كل صفحة وفي رأس اللوحة، ورقم الواتساب هو الزرّ العائم في صفحات الموقع. التغيير يسري بلا إعادة نشر.')
                        ->schema([
                            TextInput::make('platform_name')
                                ->label('اسم المنصّة')
                                ->helperText('الشعار يُرسَم من ملف العلامة، وهذا الاسم هو ما يُقرأ نصّاً — في عنوان التبويب ولقارئ الشاشة.')
                                ->maxLength(60)
                                ->required(),
                            /*
                            | ⚠️ **الزرُّ العائمُ موجودٌ من زمنٍ ولم يرَه أحد.**
                            | الرقمُ كانَ في `NEXT_PUBLIC_WHATSAPP_NUMBER` — يُدمَجُ
                            | وقتَ البناءِ ولم يُضبَطْ قطّ — وهو بعينِه عطبُ اسمِ
                            | المنصّةِ فوقَه مرّةً ثانية. ولم يفشلْ شيء: الرقمُ
                            | الفارغُ هو أيضاً كيفَ يُطفَأُ الزرُّ عن قصد.
                            |
                            | ⚠️ **وليسَ `->required()`**: الفراغُ قرارٌ — منصّةٌ
                            | بلا خطِّ واتسابٍ لا تعرضُ زرّاً يفتحُ محادثةَ غريب.
                            */
                            TextInput::make('support_whatsapp')
                                ->label('رقم الواتساب للدعم')
                                ->helperText('بالصيغة الدوليّة بلا «+» — مثال: 97455512345. اتركه فارغاً ليختفي زرّ الواتساب من الموقع.')
                                ->tel()
                                ->maxLength(20),
                        ]),
                    /*
                    | ⛔ **المنتَجُ كانَ يطلبُ تحويلاً إلى مكانٍ لا يُسمّيه.** شاشةُ
                    | الاشتراكِ تقولُ «حوِّلْ قيمة الباقة إلى حساب المنصّة» — ولا
                    | تقولُ أيُّ حساب. بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦، وقِيسَ أنّ
                    | `platform_settings` لم يكنْ فيه اسمُ بنكٍ ولا آيبان ولا محفظة.
                    |
                    | ⚠️ وكلُّها اختياريّة: منصّةٌ تُحوَّلُ إليها بالبنكِ وحدَه لا
                    | تعرضُ سطرَ محفظةٍ فارغاً. والشاشةُ تُسقِطُ ما لم يُملأ.
                    */
                    Section::make('وجهة التحويل')
                        ->description('يراها المشتري قبل أن يرفع الإيصال. اترك ما لا تستعمله فارغاً — لا يظهر.')
                        ->schema([
                            TextInput::make('transfer_bank_name')->label('اسم البنك')->maxLength(120),
                            TextInput::make('transfer_account_name')->label('اسم صاحب الحساب')->maxLength(120),
                            TextInput::make('transfer_account_number')->label('رقم الحساب')->maxLength(64),
                            TextInput::make('transfer_iban')->label('الآيبان (IBAN)')->maxLength(64),
                            TextInput::make('transfer_wallet_label')->label('اسم المحفظة الإلكترونية')->maxLength(60),
                            TextInput::make('transfer_wallet_number')->label('رقم المحفظة')->maxLength(40),
                            TextInput::make('transfer_note')
                                ->label('ملاحظة للمشتري')
                                ->helperText('مثال: اكتب اسمك في خانة الملاحظات ليُطابَق التحويل بطلبك.')
                                ->maxLength(200),
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
                            /*
                            | ⛔ **الأرقامُ الثلاثةُ في قسمٍ واحدٍ مع حدِّ الأجهزة،
                            | لا في قسمٍ ثالث.** تُقرَأُ معاً وتُحرَّرُ معاً،
                            | وتعليقُ `billing.transfer` في `PlatformSettings`
                            | يكتبُ القاعدةَ بنصِّها: عدّةُ حقولٍ متفرّقةٍ تعني
                            | عدّةَ فرصٍ لأن يُملأَ بعضُها ويُنسى الباقي.
                            */
                            TextInput::make('session_idle_days')
                                ->label('إنهاء الجلسة بعد انقطاع (بالأيام)')
                                ->helperText('جلسةٌ لم تُستخدم هذه المدّة تنتهي، ويُطلب تسجيل الدخول من جديد. صفر يعني «بلا حدّ».')
                                ->numeric()->minValue(0)->maxValue(3650)->required(),
                            TextInput::make('auth_session_retain_days')
                                ->label('مدّة الاحتفاظ بسجلّ الجلسات والأجهزة (بالأيام)')
                                ->helperText('بعدها يبقى الصفّ ويذهب «من أين»: يُمسَح العنوان وتُمسَح البصمة. لا يقلّ عن ٨ أيّام.')
                                ->numeric()->minValue(8)->maxValue(65535)->required(),
                            TextInput::make('auth_session_cap_per_user')
                                ->label('أقصى عدد جلسات منتهية مُجهَّلة لكلّ حساب')
                                ->helperText('صفر يعني «بلا سقف» فلا يُحذَف شيء. والنشطة والحديثة خارج هذا العدّ.')
                                ->numeric()->minValue(0)->maxValue(100000)->required(),
                            TextInput::make('auth_session_cap_min_age_days')
                                ->label('أقصر عمر يبلغه السقف (بالأيام)')
                                ->helperText('السقف لا يحذف صفّاً أحدث من هذا. أطول من مدّة الاحتفاظ، وإلّا لم يبقَ للحساب سجلّ يُقرَأ.')
                                ->numeric()->minValue(1)->maxValue(65535)->required(),
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
                            TextInput::make('watched_share')
                                ->label('نسبة المشاهدة التي تُعدّ «شاهد التسجيل» (من ٠٫٠٥ إلى ١)')
                                ->helperText('تُقاس بساعة الخادم منذ فتح الفيديو، لا بموضع المشغّل. تظهر للمدرّس في كشف الحضور ولا تغيّر الحالة.')
                                ->numeric()->minValue(0.05)->maxValue(1)->step(0.05)->required(),
                            TextInput::make('watched_fallback_seconds')
                                ->label('مدة «شاهد التسجيل» حين لا تُعرف مدة الفيديو (ثانية)')
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

        /*
        | ⚠️ الأرقامُ وحدَها، والتطبيعُ عندَ الكتابةِ لا عندَ القراءة. الرابطُ
        | `wa.me/<digits>` لا يقبلُ «+» ولا مسافةً ولا شَرطة، وتطبيعٌ عندَ كلِّ
        | قارئٍ هو تهجئةٌ في كلِّ ملفٍّ يقرأ. يُكتَبُ مرّةً على الشكلِ الذي
        | يُستعمَلُ به.
        */
        PlatformSettings::set(
            'platform.support_whatsapp',
            (string) preg_replace('/[^0-9]/', '', (string) ($data['support_whatsapp'] ?? '')),
            $userId,
        );

        /*
        | ⚠️ خريطةٌ واحدةٌ تُكتَبُ كاملةً في كلِّ حفظ، فالخانةُ التي أفرغَها
        | المشغِّلُ تُفرَغُ فعلاً. كتابةُ المملوءِ وحدَه تُبقي رقمَ حسابٍ قديماً
        | حيّاً بعدَ أن مسحَه صاحبُه — وهو رقمٌ يُحوَّلُ إليه مال.
        */
        PlatformSettings::set(
            'billing.transfer',
            collect(TransferInstructions::FIELDS)
                ->mapWithKeys(fn (string $field): array => [
                    $field => trim((string) ($data['transfer_'.$field] ?? '')),
                ])
                ->all(),
            $userId,
        );
        PlatformSettings::set('auth.device_limits', ['student' => (int) $data['student_device_limit']], $userId);
        PlatformSettings::set('auth.two_factor_grace_days', (int) $data['two_factor_grace_days'], $userId);
        PlatformSettings::set('auth.auth_session_cap_per_user', (int) $data['auth_session_cap_per_user'], $userId);
        PlatformSettings::set('auth.auth_session_cap_min_age_days', (int) $data['auth_session_cap_min_age_days'], $userId);
        PlatformSettings::set('auth.session_idle_days', (int) $data['session_idle_days'], $userId);
        $this->saveSessionRetention((int) $data['auth_session_retain_days']);
        PlatformSettings::set('media.max_size_bytes', (int) $data['max_size_bytes'], $userId);
        PlatformSettings::set('media.max_duration_seconds', (int) $data['max_duration_seconds'], $userId);
        PlatformSettings::set('media.grant_ttl_seconds', (int) $data['grant_ttl_seconds'], $userId);
        PlatformSettings::set('media.max_renewals', (int) $data['max_renewals'], $userId);
        PlatformSettings::set('media.watched_share', (float) $data['watched_share'], $userId);
        PlatformSettings::set('media.watched_fallback_seconds', (int) $data['watched_fallback_seconds'], $userId);

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

    /**
     * The retention, written where the privacy screen reads it.
     *
     * ⛔ THROUGH `SaveDataCategory`, NEVER WITH A RAW `update()`. That Action is
     * the only place the bounds live — zero is "erase everything older than this
     * instant", 65,536 runs `created_at + n days` off the end of the calendar and
     * raises ERROR 1441 on MySQL, killing the whole sweep including every other
     * category, and spec 038 adds a floor of its own because
     * `playback_grants.issued_ip_hash` holds the same value as a session's and is
     * pruned only a week after the grant expires. A raw write skips all three.
     *
     * ⚠️ AND THE VALUE STAYS IN `data_categories.retain_days` rather than moving
     * to `platform_settings` beside its two siblings. `DataCategoryResource` sends
     * it to «خصوصيّتي» with a sentence built from it, and a row with no duration
     * prints «يُحفظ ما دام الحساب قائماً» — a privacy notice that lies. The owner
     * asked for one place to EDIT the three numbers, which this is; where each is
     * stored is a different question.
     *
     * ⚠️ AND IT IS THE FIRST TIME A FILAMENT PAGE IN THIS TREE CALLS AN ACTION
     * FROM ANOTHER MODULE (measured: 25 such calls, all within their own module),
     * so it is named as a decision rather than left to read as an oversight. This
     * page already imports `Payments\Support\BillingSettings` and
     * `LiveSessions\Enums\ClassSessionType` — reads across the same boundary.
     * The alternative was writing the column raw, which is the one thing the
     * guards above forbid.
     */
    private function saveSessionRetention(int $days): void
    {
        $save = app(SaveDataCategory::class);

        foreach (['auth_session', 'device'] as $key) {
            $category = DataCategory::query()->where('key', $key)->first();

            if ($category !== null) {
                $save->handle(['retain_days' => $days], $category);
            }
        }
    }
}
