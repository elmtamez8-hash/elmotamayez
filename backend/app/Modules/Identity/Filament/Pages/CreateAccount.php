<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Pages;

use App\Models\User;
use App\Modules\Identity\Actions\RegisterParent;
use App\Modules\Identity\Actions\RegisterStudent;
use App\Modules\Identity\Data\RegisterParentData;
use App\Modules\Identity\Data\RegisterStudentData;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Throwable;
use UnitEnum;

/**
 * إنشاءُ حسابِ طالبٍ أو وليِّ أمرٍ نيابةً عنه، من لوحةِ المنصّة.
 *
 * ⚠️ THIS DOES NOT CONTRADICT `UserResource` BEING READ-ONLY — IT IS WHY IT CAN
 * STAY THAT WAY. That resource refuses create/edit/delete because `status`,
 * `platform_role` and `is_super_admin` in a generic form are a second door to a
 * decision the `Identity` Actions own, and because deleting from a panel walks
 * past the whole `PersonalDataOwner` contract. Every word of that still holds:
 * this page has no field for any of the three, writes no column itself, and
 * deletes nothing. It calls the SAME Action the public signup calls, so the row
 * it produces is the row that door produces — the standard
 * `TeacherApplicationResource` already sets: «clicking approve in Filament must
 * produce exactly what the endpoint produces».
 *
 * ⚠️ AND THE MINOR GATE IS NOT BYPASSED, DELIBERATELY. `RegisterStudent` treats
 * an unknown date of birth AS A MINOR (`FR-009ج`) and writes
 * `pending_guardian_consent`, which is a status that CANNOT SIGN IN. An operator
 * creating an account for a child therefore creates a blocked account until the
 * guardian consents — that is the law working, not a defect, and the helper text
 * below says so before the button is pressed rather than after.
 *
 * ⚠️ NO TERMS CONSENT IS FABRICATED, and it was measured rather than assumed:
 * `RegisterStudent` writes no `terms_consents` row at all — `terms_accepted` is
 * a checkbox on the HTTP request and nothing else, while the real signature is
 * `Payments\RecordTermsConsent`, written the moment a person agrees to OWE. So a
 * staff-created student is simply withheld at booking until they sign it
 * themselves (`is_withheld` reads the CURRENT consent), which is the system
 * behaving correctly. A consent row minted here would be a forged legal record —
 * the `terms_consents.ip_address` rule, reached from a new direction.
 *
 * ⛔ AND NO TEACHER. A teacher is not an account somebody types in: they arrive
 * through `/signup/teacher` and are vetted in `TeacherApplicationResource`'s
 * approve queue, which writes the profile, the workspace participation and the
 * trust score. A create button here would be a second door around the vetting.
 *
 * ⚠️ THE PASSWORD IS TYPED, NOT MAILED, AND THAT IS A MEASUREMENT NOT A CHOICE.
 * Production runs `MAIL_MAILER=log` — nothing leaves the server — so a «we have
 * sent them a link» design delivers silence. The operator reads the temporary
 * password out over the same channel they took the bank receipt on, and the
 * account owner changes it at `/settings`. The upgrade is a real mailer, and
 * then a reset link replaces this field.
 *
 * @property-read Schema $form
 */
class CreateAccount extends Page
{
    protected string $view = 'filament.pages.create-account';

    protected static ?string $slug = 'create-account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 19;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * ⚠️ REPEATED HERE AND NOT INHERITED FROM THE NAVIGATION — a filtered menu
     * shapes one request and not the next, and a slug is typed as easily as it
     * is clicked. EVERY TEACHER REACHES `/admin`, so without this line the panel
     * mints accounts for anyone who can open it.
     *
     * `isSuperAdmin()` is the one spelling `UserResource::canViewAny()` already
     * uses over the same model, and this page is that screen's write side. A
     * dedicated permission would be the upgrade the day a finance officer needs
     * it — platform-level by derivation, and reaching super admin through `$all`
     * exactly as it does now.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'إنشاء حساب';
    }

    public function getTitle(): string
    {
        return 'إنشاء حساب لطالب أو وليّ أمر';
    }

    public function mount(): void
    {
        $this->form->fill(['platform_role' => PlatformRole::Student->value, 'country' => 'QA']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('نوع الحساب')
                        ->description('المدرّس ليس من بينهما: يتقدّم بنفسه ويُعتمَد من شاشة «طلبات المدرّسين».')
                        ->schema([
                            Select::make('platform_role')
                                ->label('النوع')
                                ->options([
                                    PlatformRole::Student->value => 'طالب',
                                    PlatformRole::Parent->value => 'وليّ أمر',
                                ])
                                ->required()
                                // The student half of the form appears and
                                // disappears with this, so the schema has to be
                                // re-evaluated on every change.
                                ->live(),
                        ]),

                    Section::make('الحساب')
                        ->schema([
                            TextInput::make('first_name')->label('الاسم الأوّل')->required()->maxLength(100),
                            TextInput::make('last_name')->label('اسم العائلة')->maxLength(100),
                            /*
                            | ⚠️ `unique` IS SPELLED HERE AS WELL AS IN THE FORM
                            | REQUEST, and that duplication is the price of calling
                            | an Action directly: `RegisterStudentRequest` never
                            | runs on this path, so without it the first repeated
                            | address is a `QueryException` — a 500 page over the
                            | most ordinary operator mistake there is.
                            */
                            TextInput::make('email')
                                ->label('البريد')
                                ->email()
                                ->required()
                                ->maxLength(255)
                                ->rule(Rule::unique('users', 'email')),
                            TextInput::make('phone')
                                ->label('الهاتف')
                                ->required()
                                ->helperText('بالصيغة الدوليّة، مثل ‎+97455512345')
                                ->rule('regex:/^\+[1-9]\d{6,14}$/'),
                            TextInput::make('country')
                                ->label('الدولة')
                                ->required()
                                ->length(2)
                                ->helperText('رمز الدولة بحرفين، مثل QA'),
                            TextInput::make('password')
                                ->label('كلمة مرور مؤقّتة')
                                ->password()
                                // Readable on purpose: this number is dictated to
                                // its owner over the phone, and a field nobody can
                                // read is one that gets pasted somewhere it stays.
                                ->revealable()
                                ->required()
                                ->minLength(8)
                                ->helperText('بلّغْها صاحب الحساب ليغيّرها من «الإعدادات». لا بريد على الخادم يرسل رابطاً.'),
                        ])
                        ->columns(2),

                    Section::make('بيانات الطالب')
                        ->visible(fn (Get $get): bool => $get('platform_role') === PlatformRole::Student->value)
                        ->schema([
                            Select::make('school_year_slug')
                                ->label('الصفّ الدراسيّ')
                                // From the catalogue and by SLUG, never by id —
                                // the Action resolves the slug, and an id here
                                // would be a second vocabulary.
                                ->options(fn (): array => SchoolYear::query()
                                    ->activelyOffered()
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'slug')
                                    ->all())
                                ->required()
                                ->searchable(),
                            Select::make('region_slug')
                                ->label('المنطقة')
                                ->options(fn (): array => Region::query()
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'slug')
                                    ->all())
                                ->required()
                                ->searchable(),
                            /*
                            | ⚠️ REQUIRED, AND THE HELPER TEXT IS THE POINT. An
                            | absent date is read as a MINOR by `RegisterStudent`,
                            | so leaving it blank does not create an ordinary
                            | account — it creates one that cannot sign in until a
                            | guardian consents. The operator learns that here
                            | rather than from a person who cannot log in.
                            */
                            DatePicker::make('date_of_birth')
                                ->label('تاريخ الميلاد')
                                ->required()
                                ->maxDate(now()->subDay())
                                ->live()
                                ->helperText('دونَ الثامنةَ عشرةَ ⇐ الحساب موقوف حتّى موافقة وليّ الأمر (FR-009).'),
                            TextInput::make('guardian_contact')
                                ->label('هاتف وليّ الأمر')
                                ->rule('regex:/^\+[1-9]\d{6,14}$/')
                                ->required(fn (Get $get): bool => self::isMinor($get('date_of_birth')))
                                ->visible(fn (Get $get): bool => self::isMinor($get('date_of_birth')))
                                ->helperText('يُطلَب للقاصر وحده، وهو مَن تصله دعوة الموافقة.'),
                        ])
                        ->columns(2),

                    Actions::make([
                        Action::make('create')
                            ->label('أنشئ الحساب')
                            ->submit('create')
                            ->action(fn () => $this->create()),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $data = $this->form->getState();

        /*
        | ⚠️ THE SECOND FACTOR, FOR THE REASON THE ORDERS SCREEN ASKS IT: `/admin`
        | is session-authenticated and never passes through `2fa.required`, and
        | minting an account for somebody else is an identity decision. Same
        | sentence as the API, from `TwoFactorMandate`.
        */
        $actor = Auth::user();

        if ($actor instanceof User && ($refusal = TwoFactorMandate::refusalFor($actor)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

            return;
        }

        try {
            $user = $data['platform_role'] === PlatformRole::Parent->value
                ? app(RegisterParent::class)->handle(RegisterParentData::fromArray($data))
                : app(RegisterStudent::class)->handle(RegisterStudentData::fromArray([
                    ...$data,
                    /*
                    | ⚠️ FALSE, AND IT IS NOT THE SAME QUESTION AS «who typed
                    | this». The flag records that a PARENT registered their
                    | child — it is read by the guardian-consent path. Staff are
                    | neither the child nor the parent, so claiming the parent did
                    | it would put a consent decision in the wrong person's name.
                    */
                    'registered_by_parent' => false,
                ]));
        } catch (Throwable $exception) {
            // The class, never the message: Laravel interpolates a query's
            // BINDINGS into a `QueryException`, which here is somebody's email
            // and phone number on a screen and in a log (spec 013 · FR-041).
            report($exception);

            Notification::make()
                ->danger()
                ->title('تعذّر إنشاء الحساب')
                ->body('لم يُحفَظ شيء. راجعِ البيانات وأعدِ المحاولة.')
                ->send();

            return;
        }

        $blocked = $user->status === UserStatus::PendingGuardianConsent->value;

        Notification::make()
            ->success()
            ->title('أُنشئ الحساب')
            ->body($blocked
                ? "{$user->email} — الحساب موقوف حتّى يوافق وليّ الأمر، فلن يستطيع الدخول قبلها."
                : "{$user->email} — يمكنه الدخول الآن بكلمة المرور المؤقّتة.")
            ->persistent()
            ->send();

        $this->form->fill(['platform_role' => $data['platform_role'], 'country' => 'QA']);
    }

    /**
     * ⚠️ THE SAME TEST `RegisterStudent` MAKES, and it has to agree with it: the
     * Action treats an UNKNOWN date as a minor too, so a blank field here asks
     * for the guardian's number rather than hiding it.
     */
    private static function isMinor(mixed $dateOfBirth): bool
    {
        if (! is_string($dateOfBirth) || $dateOfBirth === '') {
            return true;
        }

        return CarbonImmutable::parse($dateOfBirth)->diffInYears(CarbonImmutable::now()) < 18;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            // ⚠️ THE DOWNSTREAM STEP, LINKED. An operator creating an account from
            // a WhatsApp receipt is about to grant that person their sessions;
            // spec 024's screen is where that happens, and a flow whose second
            // half has to be found in a menu is a flow half of them stop after.
            Action::make('grant')
                ->label('منح اشتراك لطالب')
                ->icon(Heroicon::OutlinedGift)
                ->url('/admin/grant-credit-subscription')
                ->visible(fn (): bool => Auth::user() instanceof User),
        ];
    }
}
