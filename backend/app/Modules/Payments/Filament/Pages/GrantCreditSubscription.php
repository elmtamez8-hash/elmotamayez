<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Payments\Actions\ListCreditPackages;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\PlanShape;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Support\CountedNoun;
use BackedEnum;
use Carbon\CarbonInterface;
use DomainException;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
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
 * ⛔ AND THE GRANT FORM STILL HAS NO APPROVE BUTTON, BY REQUIREMENT (024 · FR-008أ).
 * The save ends at a PENDING order — fold the two into one press and the approval
 * becomes a signature on a blank page.
 *
 * ⚠️ AND THE QUEUE ABOVE IT DOES NOT BREAK THAT RULE, BUT ONLY BECAUSE OF A
 * PREDICATE — SO IT IS WRITTEN DOWN HERE. 027 · FR-016 puts the pending
 * SUBSCRIPTION orders on this page with «اعتمد» beside each. What keeps 024's rule
 * intact is that the form below creates `OrderKind::Credits` (through
 * `PurchaseCredits`) while the table reads `OrderKind::Subscription`: the two sets
 * do not intersect, so no officer can approve a row they just wrote. That is a
 * consequence of a filter, not a rule anybody stated — add a kind to
 * {@see self::pendingSubscriptions()} and 024's requirement reopens with nothing
 * saying it existed. `SubscriptionQueueTest` asserts the disjointness.
 *
 * ⚠️ AND THE GUARD IS `kind`, NOT THE STATUS FILTER. That distinction became load
 * bearing in 027 · FR-027, which widened this query to keep recently APPROVED
 * subscription orders listed so a failed activation is visible. Approved rows
 * carry no decision control — both buttons are `->visible(isPending())` on
 * `OrderResource` — so widening the statuses cannot reopen 024's rule; widening
 * the kind still would.
 *
 * @property-read Schema $form
 */
class GrantCreditSubscription extends Page implements HasTable
{
    /**
     * How long an approved subscription order stays on the queue so a failed
     * activation can be seen (FR-027). Long enough to survive a weekend and a
     * drained queue; short enough that the list stays a queue.
     */
    private const ACTIVATION_WATCH_DAYS = 14;

    /** What the officer reads when the failure was not written for them. */
    public const GENERIC_FAILURE = 'تعذّر إتمامُ العملية. حاول مرّةً أخرى، وإن تكرّر فأبلِغ الدعم الفنّي.';

    use InteractsWithTable;

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

    /**
     * الاسمُ يُسمّي النصفَينِ، لأنّ الشاشةَ منتجانِ لا واحد.
     *
     * ⛔ كانَ «منح اشتراك لطالب» فوقَ استمارةٍ تُنشئُ `OrderKind::Credits` عبرَ
     * {@see PurchaseCredits} — أي تمنحُ **رصيداً** لا اشتراكاً — بينما الجدولُ
     * فوقَها يقرأُ `OrderKind::Subscription`. وحقلُ الحزمةِ كانَ اسمُه «الباقة»،
     * وهي الكلمةُ عينُها التي تحملُها باقاتُ الاشتراكِ في الجدولِ أعلاه.
     *
     * فسألَ المالكُ في ٢٠٢٦-٠٩-٢٠ عن تناقضٍ ظاهر: الاستمارةُ تعرضُ «أربع حصص
     * فردية» والعمودُ فوقَها يقولُ «شهر واحد». ولا تناقضَ في البيانات — قِيسَ أنّ
     * `plans` صفّانِ شهريّانِ وأنّ الستّةَ المعروضةَ `credit_packages` — **وثمنُ
     * التسميةِ كانَ انتباهَ قارئٍ مدقّقٍ استنتجَ عطباً ليسَ هناك**.
     *
     * ⚠️ ولا يُدمَجُ النصفانِ: افتراقُ `OrderKind` بينَهما هو ما يمنعُ الموظّفَ من
     * اعتمادِ صفٍّ كتبَه بيدِه (قاعدةُ ٠٢٤)، كما تقولُ ترويسةُ هذا الصفِّ أعلاه.
     * الإصلاحُ نصوصٌ وحدَها، بلا لمسِ الفصلِ الحامل.
     */
    public static function getNavigationLabel(): string
    {
        return 'طلبات الاشتراك · منح رصيد';
    }

    public function getTitle(): string
    {
        return 'طلبات الاشتراك · منح رصيد';
    }

    /**
     * ⛔ لم تكن هذه الدالّةُ موجودةً إطلاقاً — والشاشةُ كلُّها لم تكن تعمل.
     *
     * `$data` يبدأُ `[]`، فبلا تهيئةٍ لا مفتاحَ لأيِّ حقلٍ في حالةِ المكوّن.
     * وحقولُ Filament المعقّدةُ — المنتقي المبحوثُ ورفعُ الملفّ — تربطُ نفسَها
     * بـ`$wire.entangle('data.student')`، و**entangle يشترطُ وجودَ الخاصّيّةِ
     * سلفاً**، فيرمي ويستسلم:
     *
     *     Livewire Entangle Error: Livewire property ['data.student'] cannot be
     *     found on component  (وكذلك `data.course` و`data.receipt`)
     *
     * فالطالبُ والكورسُ يظهرانِ مختارَينِ على الشاشة — Choices.js يرسمُهما في
     * المتصفّح — **ولا تصلُ قيمتُهما الخادمَ أبداً**، والإيصالُ لا يُرفَع. قِيسَ
     * على الإنتاج 2026-09-04: بعدَ اختيارِ الثلاثةِ كانت حالةُ المكوّنِ
     * `{"package":"2"}` وحدَها — والباقةُ `<select>` عاديٌّ بـ`wire:model`، وهو
     * يُنشئُ المفتاحَ عندَ التغيير، فنجا وحدَه.
     *
     * ⚠️ **ولا يراه اختبارُ Livewire من مسارِه الطبيعيّ**: `fillForm()` يكتبُ
     * الحالةَ عبرَ المخطَّطِ مباشرةً فيتخطّى الربطَ في المتصفّحِ كلَّه — فمرَّ
     * اختبارُ التسعيرِ أخضرَ فوقَ شاشةٍ لا تعملُ إطلاقاً. الحارسُ الوحيدُ الممكنُ
     * هناكَ هو التوكيدُ على **وجودِ المفاتيح** بعدَ التركيب، وهو ما يفعلُه
     * `StaffCreditGrantTest`.
     *
     * والنداءُ نفسُه كان مكتوباً في نهايةِ `save()` وحدَها — أي أنّ الشاشةَ كانت
     * تُهيَّأُ بعدَ أوّلِ حفظٍ ناجحٍ فقط، وهو حفظٌ لم يكن ممكناً أصلاً.
     */
    public function mount(): void
    {
        $this->form->fill();
    }

    /**
     * الطلباتُ المعلَّقةُ التي أرسلَها الطلابُ بأنفسِهم (027 · FR-016 · FR-017).
     *
     * ⚠️ THE FOUR MIDDLE COLUMNS COME FROM THE ORDER'S OWN SNAPSHOT, NOT FROM A
     * LOOKUP. A Filament column runs once per row, so a query inside one is an
     * N+1 by construction — and the snapshot is also what keeps the row readable
     * after the plan is renamed or the group archived (FR-013 · FR-014).
     *
     * ⚠️ AND NOTHING HERE IS `sortable()` OR `searchable()`. Both would compile
     * to `JSON_EXTRACT(metadata, …)` inside `ORDER BY`/`WHERE` — a function on an
     * unindexed column, on a query that is already the platform's whole order
     * table.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query($this->pendingSubscriptions())
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('لا طلبات اشتراك معلَّقة')
            ->emptyStateDescription('سيظهر هنا كلُّ طلبِ اشتراكٍ أرسلَه طالبٌ من صفحةِ الكورس، بإيصالِه ومجموعتِه.')
            ->columns([
                TextColumn::make('user.name')->label('الطالب')->description(fn (Order $record): string => (string) $record->user->email)->placeholder('—'),
                TextColumn::make('teacher')->label('المدرّس')->placeholder('—')
                    ->state(fn (Order $record): ?string => SubscriptionIntent::fromOrder($record)?->teacherName),
                TextColumn::make('course.title')->label('الكورس')->placeholder('—'),
                /*
                | 036 . T069 -- IT PRINTED «٠ يوماً» FOR EVERY HOURS PURCHASE.
                | `durationDays` is null on that shape and `$days.' يوماً'` was
                | reached through a `=== null` check that a zero passes, so the
                | officer deciding whether to approve read a subscription of no
                | length at all. Read from the ORDER SNAPSHOT, which costs zero
                | queries -- a lookup on `plans` inside a column body runs once per
                | row and would turn `SubscriptionQueueTest`'s budget red.
                */
                TextColumn::make('shape')->label('ما اشتُري')->placeholder('—')
                    ->state(function (Order $record): ?string {
                        $intent = SubscriptionIntent::fromOrder($record);

                        return $intent === null
                            ? null
                            : PlanShape::describe($intent->durationDays, $intent->sessionCount);
                    }),
                TextColumn::make('target')->label('المجموعة')->placeholder('—')->wrap()
                    // «حصص خاصّة» in words, never a blank — a dash here is
                    // indistinguishable from data that failed to load (FR-017).
                    ->state(fn (Order $record): ?string => SubscriptionIntent::fromOrder($record)?->targetLabel()),
                TextColumn::make('amount_minor')->label('المبلغ')
                    ->formatStateUsing(fn (mixed $state, Order $record): string => number_format(((int) $state) / 100, 2).' '.$record->currency),
                TextColumn::make('receipt')->label('الإيصال')
                    ->state(fn (Order $record): HtmlString|string => self::receiptLink($record)),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                /*
                | The visible half of FR-027. Activation runs on a worker after
                | the approval commits, so an order can be approved — money taken
                | — while the subscription behind it was never written. Derived
                | from the `withExists` above rather than looked up per row.
                |
                | ⚠️ IT ANSWERS «هل كُتب الاشتراك؟», NOT «هل اكتمل كلُّ شيء». A
                | failure at the membership step leaves a subscription behind and
                | reads as done here; that half is guarded before the money moves
                | (`ApproveOrder::assertStillActionable`) and healed by the retry,
                | which is why one flag is enough rather than a second subquery on
                | every page load.
                |
                | ⛔ **وثلاثُ حالاتٍ لا حالتان، وإلّا أدانَ العاملَ قبلَ أن يعمل.**
                | التفعيلُ يقعُ على عامِلٍ بعدَ ثبوتِ الاعتماد، فبينَ الضغطةِ
                | وكتابةِ الاشتراكِ نافذةٌ حقيقيّة — قِيسَت على الإنتاج ٢٠٢٦-٠٩-١٦:
                | الاعتمادُ 14:16:39 والاشتراكُ 14:16:42، **ثلاثُ ثوانٍ** رُسِمَت
                | فيها لافتةٌ حمراءُ تقولُ «لم يُنشأ الاشتراك» عن اشتراكٍ كُتِبَ
                | بعدَها بلحظة. والموظّفُ الذي يقرؤُها يضغطُ ثانيةً أو يُبلِّغُ عن
                | عطبٍ ليسَ هناك.
                |
                | فالحمراءُ لا تُقالُ إلّا بعدَ أن تنقضيَ مهلةُ العامِل. وقبلَها
                | «قيد التفعيل» — وهو وصفُ الحقيقةِ لا تلطيفٌ لها.
                */
                TextColumn::make('activation')->label('التفعيل')
                    ->state(fn (Order $record): string => match (true) {
                        $record->isPending() => '—',
                        self::wasActivated($record) => 'مكتمل',
                        self::awaitingActivation($record) => 'قيد التفعيل…',
                        default => self::missingLabel($record),
                    })
                    ->badge()
                    ->color(fn (Order $record): string => match (true) {
                        $record->isPending() => 'gray',
                        self::wasActivated($record) => 'success',
                        self::awaitingActivation($record) => 'warning',
                        default => 'danger',
                    }),
            ])
            ->recordActions([
                // ⚠️ THE RESOURCE'S OWN ACTIONS, VERBATIM. They carry
                // `->authorize()`, `refusedForTwoFactor()`, the mandatory reason
                // and the IP/user-agent that reach `ApproveOrder`/`RejectOrder`.
                // A button re-implemented here loses all four in silence, which
                // is FR-018…FR-021 and FR-037 gone with nothing red.
                OrderResource::approveAction(),
                OrderResource::rejectAction(),
            ])
            /*
            | ⚠️ **الاستطلاعُ نصفُ الإصلاحِ الآخر، لا زينة.** «قيد التفعيل» وعدٌ
            | بأنّ الجوابَ آتٍ، وبلا هذا السطرِ يبقى الوعدُ معلَّقاً حتّى يُعيدَ
            | الموظّفُ التحميلَ بيدِه — وهو بالضبطِ ما فعلَه مَن أبلغَ عن اللافتةِ
            | الحمراء. عشرُ ثوانٍ أوسعُ من النافذةِ المقيسة (ثلاث)، والصفحةُ
            | ميزانيّةُ استعلاماتِها مُثبَّتةٌ باختبارٍ بجوارِ هذا الملفّ فلا
            | يزيدُها عددُ الطلبات.
            */
            ->poll('10s');
    }

    /**
     * @return Builder<Order>
     */
    private function pendingSubscriptions(): Builder
    {
        return Order::query()
            /*
            | ⚠️ THE BYPASS IS DECLARED ON THE ROOT **AND REPEATED IN THE EAGER
            | LOAD**. A platform officer inherits `users.last_workspace_id` like
            | anybody else, and `->with('course')` runs its own query on which
            | `Course`'s workspace scope applies afresh — every order outside the
            | officer's own workspace then renders a blank course, with no error
            | anywhere. That is the fifth layer of the 024 defect, and it is the
            | one that raises no status code at all.
            |
            | `user` needs no bypass: `users` is platform-owned.
            */
            ->withoutWorkspaceScope()
            ->where('kind', OrderKind::Subscription)
            /*
            | ⚠️ APPROVED ORDERS STAY LISTED FOR A WHILE, AND THAT IS FR-027.
            | Activation is queued and runs after the approval commits, so a
            | failure there leaves an approved order, money taken, and a student
            | in no course and no group — «عملاً ناقصاً» that must be READABLE ON A
            | SCREEN rather than left in `failed_jobs`. Filtering to pending alone
            | removed the row from this table at the exact instant it became
            | interesting, so no derived column could ever have described it.
            |
            | Bounded by a window rather than by a flag: an approved order that
            | activated correctly is finished business, and a queue that keeps
            | every one of them for ever is a queue nobody reads.
            */
            ->where(fn (Builder $query): Builder => $query
                ->awaitingDecision()
                ->orWhere(fn (Builder $approved): Builder => $approved
                    ->where('status', 'approved')
                    ->where('approved_at', '>=', now()->subDays(self::ACTIVATION_WATCH_DAYS))))
            ->with([
                'course' => fn ($relation) => $relation->withoutGlobalScope(WorkspaceScope::class),
                'user',
            ])
            /*
            | ⚠️ EXISTENCE SUBQUERIES, NOT A CLOSURE PER ROW. A column body runs
            | once per row, so a lookup inside one is an N+1 by construction — the
            | rule `ClassSessionResource` already wrote down. And each carries its
            | own scope bypass: `Subscription` and `CohortMembership` are both
            | workspace-scoped, so the officer's own fallback workspace would
            | otherwise make every foreign row read as «not activated».
            */
            ->withExists([
                'subscription as has_subscription' => fn ($relation) => $relation->withoutGlobalScope(WorkspaceScope::class),
                /*
                | ⛔ 036 . T069 -- THE SECOND PROOF, AND WITHOUT IT EVERY HOURS
                | PURCHASE SITS ON THIS QUEUE MARKED «ناقص» FOR EVER. That shape
                | writes NO subscription row by design, so `has_subscription` is
                | false on a purchase that completed perfectly: a red badge the
                | officer cannot clear, on money that was taken and credits that
                | were poured. The sale row is what this shape does write, keyed on
                | the order, so it is the existence question for it.
                */
                'creditPurchase as has_credit_purchase' => fn ($relation) => $relation->withoutGlobalScope(WorkspaceScope::class),
            ]);
    }

    /**
     * مهلةُ العامِل: بعدَها وحدَها يُقالُ «ناقص».
     *
     * ⚠️ **دقيقةٌ سخيّةٌ عن قصد.** المقيسُ ثلاثُ ثوانٍ على إنتاجٍ سليم، والرقمُ
     * هنا ليسَ تقديراً للسرعةِ بل سقفاً للصبر: أن يُقالَ «قيد التفعيل» عن عطبٍ
     * حقيقيٍّ دقيقةً زائدةً أرخصُ من أن يُقالَ «ناقص» عن اشتراكٍ يُكتَبُ بعدَ
     * لحظة — الأولى تأخيرٌ في الخبر، والثانيةُ خبرٌ كاذبٌ يدفعُ الموظّفَ إلى
     * ضغطةٍ ثانيةٍ أو بلاغٍ عن عطبٍ ليسَ هناك.
     *
     * ⚠️ ويُقارَنُ بـ`greaterThan` لا بفارقٍ عدديّ: `diffInSeconds` على تاريخٍ
     * في الماضي أو المستقبلِ يُغيِّرُ إشارتَه، وهذا المستودعُ دفعَ ثمنَ ذلك مرّةً
     * في توكيدةِ مدّةِ تذكرةِ البثّ — مرَّت خضراءَ لأيِّ مدّةٍ كانت.
     */
    private const ACTIVATION_GRACE_SECONDS = 60;

    /**
     * «اكتمل» for either shape (036 . T069).
     *
     * ⚠️ IT IS TWO EXISTENCE FLAGS, NOT ONE READ OF THE SNAPSHOT. Branching on
     * `isSessionShaped()` and then asking only the matching flag would report an
     * hours purchase as complete the instant its shape was known -- which is at
     * the moment of BUYING, before the listener has run at all. What is being
     * asked is whether the activation actually wrote something, so the answer is
     * «something got written», whichever thing this order's shape writes.
     */
    private static function wasActivated(Order $order): bool
    {
        return (bool) $order->getAttribute('has_subscription')
            || (bool) $order->getAttribute('has_credit_purchase');
    }

    /**
     * The red badge names the thing that is actually missing.
     *
     * «لم يُنشأ الاشتراك» about an hours purchase sends the officer looking for a
     * subscription row that was never going to exist, which is worse than no
     * sentence at all.
     */
    private static function missingLabel(Order $order): string
    {
        return SubscriptionIntent::fromOrder($order)?->isSessionShaped() === true
            ? 'ناقص — لم يُصَبَّ الرصيد'
            : 'ناقص — لم يُنشأ الاشتراك';
    }

    private static function awaitingActivation(Order $order): bool
    {
        /*
         | ⚠️ `instanceof` لا `!== null`: العمودُ نصٌّ في الهجرة، والذي يجعلُه
         | تاريخاً هو `casts()` على النموذج — فالمحلِّلُ يقرؤُه نصّاً وهو محقّ في
         | أنّه لا يضمنُ الصبّ. والفحصُ يفشلُ في الاتّجاهِ القديمِ الظاهر: لو
         | سقطَ الصبُّ يوماً عادَتِ اللافتةُ حمراءَ فوراً كما كانت، لا صامتة.
         */
        $approvedAt = $order->getAttribute('approved_at');

        return $approvedAt instanceof CarbonInterface
            && $approvedAt->greaterThan(now()->subSeconds(self::ACTIVATION_GRACE_SECONDS));
    }

    /**
     * ⚠️ A TEMPORARY SIGNED URL, NEVER `getFirstMediaUrl()`. The receipt lives on
     * the `local` disk, which has no `url` in `config/filesystems.php` — spatie
     * then falls back to the conventional `/storage/...` path, which serves the
     * PUBLIC disk. Every such link 403s, and any that worked would be a financial
     * document on a public path (FR-035).
     */
    private static function receiptLink(Order $order): HtmlString|string
    {
        $media = $order->latestReceipt();

        if ($media === null) {
            return 'لا إيصال';
        }

        $url = URL::temporarySignedRoute('orders.receipt', now()->addMinutes(15), ['order' => $order->uuid]);

        return new HtmlString('<a href="'.e($url).'" target="_blank" rel="noopener" '
            .'class="fi-link fi-size-sm" style="text-decoration:underline">افتحِ الإيصال</a>');
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
                                ->label('حزمة الحصص')
                                ->required()
                                ->options(fn (): array => CreditPackage::query()
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->orderBy('credits')
                                    ->get()
                                    ->mapWithKeys(fn (CreditPackage $p): array => [
                                        $p->getKey() => $p->name.' — '.CountedNoun::of((int) $p->credits, ['one' => 'حصة واحدة', 'two' => 'حصتان', 'few' => 'حصص', 'many' => 'حصة', 'other' => 'حصة']),
                                    ])
                                    ->all())
                                // The `in` rule Filament derives from `options()`
                                // is what a package retired between opening this
                                // screen and saving it trips — see the constant.
                                ->validationMessages(['in' => PurchaseCredits::PACKAGE_RETIRED])
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

        /*
        | ⚠️ THE SECOND FACTOR, ASKED HERE BECAUSE THE PANEL NEVER PASSES THROUGH
        | `2fa.required`. This page mints a balance out of a bank receipt — the
        | widest money decision in the product — and `/admin` is
        | session-authenticated, so the middleware the API's own approval routes
        | carry never runs for it. The sentence is `TwoFactorMandate`'s, so the
        | panel and the API refuse the same operation in the same words.
        */
        if (($refusal = TwoFactorMandate::refusalFor($officer)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

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
        } catch (DomainException $e) {
            // The Actions' own Arabic sentences, unchanged: they name the reason
            // (a retired package, an unpriceable course, a ceiling reached) and
            // a generic message here would hide which.
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (Throwable $e) {
            /*
            | ⚠️ ANYTHING ELSE IS NOT A SENTENCE FOR A READER. A `QueryException`'s
            | message carries its BOUND VALUES — a student's email, an amount —
            | and a notification title is the one place in the panel guaranteed
            | to be read. Only a `DomainException` was written for a person; the
            | class is logged, never the message, which may carry the same
            | bindings into a line that leaves the building.
            */
            Log::error('payments.grant_credit_subscription.failed', ['exception' => $e::class]);

            Notification::make()->danger()->title(self::GENERIC_FAILURE)->send();

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
     * الناقصُ بالاسم، لا عدُّ الثلاثةِ من جديد.
     *
     * وهو شرطٌ منفصلٌ عن `previewAmount()` عمداً: إبقاءُ `instanceof` في جملةِ
     * `if` هناكَ هو ما يُبقي التضييقَ قائماً لما تحتَها — وبناءُ الرسالةِ داخلَها
     * يُفقِدُه، فيقرأُ PHPStan `Collection|null` في نداءِ التسعير.
     */
    private static function missingChoices(mixed $student, mixed $course, mixed $packageId): string
    {
        $missing = array_values(array_filter([
            $student instanceof User ? null : 'الطالب',
            $course instanceof Course ? null : 'الكورس',
            $packageId === null ? 'حزمة الحصص' : null,
        ]));

        return 'اختر '.implode(' و', $missing).'.';
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

        /*
        | ⚠️ يُسمّى الناقصُ ولا تُعدُّ الثلاثة. جملةٌ تطلبُ الثلاثةَ بعدَ اختيارِ
        | اثنَينِ منها تُقرأُ على أنّها عطل: الموظّفُ يرى ما اختارَه أمامَه ويرى
        | الشاشةَ تطلبُه ثانيةً، فيستنتجُ أنّ اختيارَه لم يُسجَّلْ ويبحثُ عن خللٍ
        | في مكانٍ سليم. والشاشةُ تعرفُ الناقصَ بالضبط.
        */
        if (! $student instanceof User || ! $course instanceof Course || $packageId === null) {
            return self::missingChoices($student, $course, $packageId);
        }

        $officer = Auth::user();

        if (! $officer instanceof User) {
            return '—';
        }

        try {
            $offers = app(ListCreditPackages::class)->handle($student, $course, $officer);
        } catch (DomainException $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            // The preview is printed inside the form — the same leak as the
            // save's notification, on a line re-rendered at every change.
            Log::error('payments.grant_credit_subscription.preview_failed', ['exception' => $e::class]);

            return self::GENERIC_FAILURE;
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
