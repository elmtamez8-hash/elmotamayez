<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Pages;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\ListCreditPackages;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnitEnum;

/**
 * منحُ اشتراكِ حصصٍ لطالبٍ بعدَ التحقّقِ من إيصالٍ بنكيّ (spec 024 · US1).
 *
 * ⚠️ `/admin`, NEVER `/manage`, AND `User::mayAccessAdminPanel()` IS WHY. That
 * method admits the super admin and `platform_staff` and nobody else — its own
 * comment says a finance officer's only screens are in this panel. A teacher
 * able to reach this page would be minting themselves an income.
 *
 * ⚠️ AND THE PAGE HOLDS NO LOGIC. Four Action calls and a transaction: the price
 * comes from the same Action the student's own screen prices with, the order from
 * the one place credits are ever sold, and the receipt from the one path that
 * records the method, the IP and the user agent. A number computed here would be
 * a second answer that ages at the first pricing change, and a second write path
 * for a receipt loses one of those three fields silently.
 *
 * ⛔ AND THERE IS NO APPROVE BUTTON HERE, BY REQUIREMENT (FR-008أ). The save ends
 * at a PENDING order. Approval is a second, deliberate step on the orders screen,
 * where the receipt is opened and the amount matched — fold the two into one
 * press and the approval becomes a signature on a blank page, which is the exact
 * thing approval exists to prevent.
 *
 * @property-read Schema $form
 */
class GrantCreditSubscription extends Page
{
    protected string $view = 'filament.pages.grant-credit-subscription';

    protected static ?string $slug = 'grant-credit-subscription';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 24;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * ⚠️ THE GUARD IS REPEATED HERE AND NOT INHERITED FROM THE NAVIGATION.
     *
     * A filtered menu shapes one request and not the next — the slug is typed
     * as easily as it is clicked. `Gate::before` waves a super admin past every
     * policy, which is correct and is also why the permission is asked on the
     * page itself rather than assumed from a role.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::BILLING_PURCHASE_APPROVE) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'منح اشتراك لطالب';
    }

    public function getTitle(): string
    {
        return 'منح اشتراك لطالب';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('الطالب والكورس')
                        ->description('يُنشئ هذا طلباً معلَّقاً بلقطة سعره. لا يتحرّك رصيدٌ واحد قبل الاعتماد، '
                            .'والاعتماد خطوةٌ ثانية على شاشة الطلبات بعد فتح الإيصال ومطابقة المبلغ.')
                        ->schema([
                            Select::make('student')
                                ->label('الطالب')
                                ->required()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => User::query()
                                    ->where(fn ($q) => $q->where('email', 'like', "%{$search}%")
                                        ->orWhere('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%"))
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn (User $u): array => [$u->getKey() => $u->name.' — '.$u->email])
                                    ->all())
                                // `whereKey()->first()`, never `find()`: the option value is `mixed`
                                // and `find()` with an array answers a Collection, which has no email.
                                ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->first()?->email)
                                ->helperText('ابحث بالبريد أو الاسم. الطالب لا يحتاج تسجيلاً سابقاً في الكورس.')
                                ->live(),

                            /*
                            | ⚠️ `withoutWorkspaceScope()` — declared, and the
                            | reason is the whole feature: the officer belongs to
                            | no teacher's workspace, and the context falls back
                            | to their own `last_workspace_id` if they happen to
                            | have one. Scoped, this picker shows a handful of
                            | courses or none, with nothing saying why.
                            */
                            Select::make('course')
                                ->label('الكورس')
                                ->required()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => Course::query()
                                    ->withoutWorkspaceScope()
                                    ->where('title', 'like', "%{$search}%")
                                    ->limit(20)
                                    ->pluck('title', 'id')
                                    ->all())
                                ->getOptionLabelUsing(fn ($value): ?string => Course::query()
                                    ->withoutWorkspaceScope()->whereKey($value)->first()?->title)
                                ->live(),

                            Select::make('package')
                                ->label('الباقة')
                                ->required()
                                ->options(fn (): array => CreditPackage::query()
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->orderBy('credits')
                                    ->get()
                                    ->mapWithKeys(fn (CreditPackage $p): array => [
                                        $p->getKey() => $p->name.' — '.$p->credits.' حصص',
                                    ])
                                    ->all())
                                ->live(),

                            /*
                            | FR-006 — the amount BEFORE the save, so the officer
                            | matches it against the receipt in their hand rather
                            | than discovering a mismatch after an order exists.
                            | Read from the same Action the student's screen uses;
                            | computing it here would be a second number that ages
                            | at the first pricing change.
                            */
                            Placeholder::make('preview')
                                ->label('المبلغ المتوقَّع')
                                ->content(fn (): string => $this->previewAmount()),
                        ]),

                    Section::make('الإيصال')
                        ->description('صورة التحويل البنكي كما وصلت من الطالب. مستندٌ ماليّ: يُحفَظ على قرصٍ '
                            .'خاصّ ولا يُخدَم من مسارٍ عامّ.')
                        ->schema([
                            FileUpload::make('receipt')
                                ->label('صورة الإيصال')
                                ->required()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                ->maxSize(10240)
                                // ⚠️ The file is handed to `UploadPaymentReceipt`
                                // as-is; letting Filament store it first would
                                // put a financial document on a second disk that
                                // nothing cleans up.
                                ->storeFiles(false),
                        ]),

                    Actions::make([
                        Action::make('grant')->label('إنشاء الطلب المعلَّق')->submit('grant'),
                    ]),
                ])->livewireSubmitHandler('grant'),
            ])
            ->statePath('data');
    }

    public function grant(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $officer = Auth::user();
        $student = User::query()->find($data['student'] ?? null);
        $course = Course::query()->withoutWorkspaceScope()->find($data['course'] ?? null);
        $package = CreditPackage::query()->find($data['package'] ?? null);
        $receipt = $data['receipt'] ?? null;
        $receipt = is_array($receipt) ? reset($receipt) : $receipt;

        if (! $officer instanceof User || ! $student instanceof User
            || ! $course instanceof Course || ! $package instanceof CreditPackage
            || ! $receipt instanceof UploadedFile) {
            Notification::make()->danger()->title('بيانات ناقصة، لم يُنشأ شيء.')->send();

            return;
        }

        try {
            /*
            | One transaction: an order with no receipt behind it is a row the
            | officer cannot act on and the student never made — and it would sit
            | in the pending list looking like somebody's unpaid purchase.
            */
            $order = DB::transaction(function () use ($officer, $student, $course, $package, $receipt): Order {
                $purchase = app(PurchaseCredits::class)->handle(
                    $student,
                    $course,
                    $package,
                    null,
                    $officer,
                );

                $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

                app(UploadPaymentReceipt::class)->handle($order, $receipt, $officer);

                return $order;
            });
        } catch (Throwable $e) {
            // The Actions' own Arabic sentences, unchanged: they name the reason
            // (a retired package, an unpriceable course, a ceiling reached) and
            // a generic message here would hide which.
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('أُنشئ طلبٌ معلَّق لـ'.$student->name)
            ->body('افتح شاشة الطلبات لمراجعة الإيصال واعتماده. لم يُضَف رصيدٌ بعد.')
            ->send();

        $this->form->fill();
    }

    /**
     * The amount this grant would create, or a sentence saying why there is none.
     */
    private function previewAmount(): string
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getRawState();

        $student = User::query()->find($state['student'] ?? null);
        $course = Course::query()->withoutWorkspaceScope()->find($state['course'] ?? null);
        $packageId = $state['package'] ?? null;

        if (! $student instanceof User || ! $course instanceof Course || $packageId === null) {
            return 'اختر الطالب والكورس والباقة.';
        }

        $officer = Auth::user();

        if (! $officer instanceof User) {
            return '—';
        }

        try {
            $offers = app(ListCreditPackages::class)->handle($student, $course, $officer);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        foreach ($offers as $offer) {
            if ((int) $offer['package']->getKey() === (int) $packageId) {
                return number_format($offer['price']->totalMinor / 100, 2).' '.$offer['price']->currency;
            }
        }

        // An empty list is the honest answer for a teacher who stopped delivering
        // or has no approved rate — and it is the answer the officer needs BEFORE
        // saving, not a 422 afterwards.
        return 'لا تسعير متاح لهذا الكورس حاليّاً.';
    }
}
