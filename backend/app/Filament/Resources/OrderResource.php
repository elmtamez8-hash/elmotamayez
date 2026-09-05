<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'الطلبات';
    }

    public static function getModelLabel(): string
    {
        return 'طلب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطلبات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('الطلب')
                    ->description('المبلغُ والمشتري والكورسُ ثابتة — تُكتَبُ عندَ إنشاءِ الطلبِ ولا تُعدَّلُ بعدَه.')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('المشتري')
                            ->relationship('user', 'email')
                            ->disabled(),
                        Select::make('course_id')
                            ->label('الكورس')
                            ->relationship('course', 'title')
                            ->disabled(),
                        TextInput::make('amount_minor')
                            ->label('المبلغ (بالوحدات الصغرى)')
                            ->integer()
                            ->disabled(),
                        TextInput::make('currency')
                            ->label('العملة')
                            ->disabled(),
                    ]),

                /*
                | ⛔ الإيصالُ لم يكن على هذه الشاشةِ إطلاقاً — والشاشةُ هي مكانُ
                | الاعتماد.
                |
                | تعليقُ المسارِ في `routes/api.php` يقولُ «`OrderResource` هو من
                | يسكُّ التوقيع، ولمن أجازَته سياسةُ `view` وحدَه»، وتعليقُ
                | {@see GrantCreditSubscription} يقولُ إنّ الاعتمادَ «خطوةٌ ثانيةٌ
                | يُفتَحُ فيها الإيصالُ ويُطابَقُ المبلغ» — و**لا كلمةَ `receipt`
                | كانت في هذا الملفِّ كلِّه**. فالموظّفُ يرفعُ الإيصالَ ثمّ لا يجدُه،
                | ويعتمدُ على بياضٍ: وهو بعينُه ما وُجِدَت خطوةُ الاعتمادِ لتمنعَه.
                |
                | ⚠️ ورابطٌ موقَّعٌ مؤقّتٌ لا مسارٌ عامّ: الملفُّ على قرصٍ خاصّ،
                | والمتصفّحُ يفتحُ الرابطَ كتنقّلٍ أعلى المستوى فلا يحملُ رأسَ
                | مصادقة. التوقيعُ يُسَكُّ هنا لمن فتحَ الصفحةَ سلفاً، وينتهي —
                | فهو إذنٌ مُنِحَ لا طريقٌ يمشي إليه أحد.
                */
                Section::make('الإيصال')
                    ->description('صورةُ التحويل كما رفعها الطالب أو الموظّف. افتحْها وطابقِ المبلغَ قبلَ الاعتماد.')
                    ->schema([
                        Placeholder::make('receipt')
                            ->hiddenLabel()
                            ->content(function (?Order $record): HtmlString|string {
                                $media = $record?->getFirstMedia('receipt');

                                if ($media === null) {
                                    return 'لا إيصالَ على هذا الطلب.';
                                }

                                $url = URL::temporarySignedRoute(
                                    'orders.receipt',
                                    now()->addMinutes(15),
                                    ['order' => $record->uuid],
                                );

                                $link = '<a href="'.e($url).'" target="_blank" rel="noopener" '
                                    .'class="fi-link fi-size-sm" style="text-decoration:underline">'
                                    .e($media->file_name).' — '.number_format($media->size / 1024).' ك.ب</a>';

                                // معاينةٌ داخلَ الصفحةِ للصور: المطابقةُ عينٌ على
                                // رقمٍ، لا نقرةٌ على تبويبٍ جديد. وPDF لا يُعرَضُ
                                // في `img`، فيبقى له الرابطُ وحدَه.
                                $preview = str_starts_with((string) $media->mime_type, 'image/')
                                    ? '<img src="'.e($url).'" alt="" style="max-width:28rem;margin-top:.5rem;border-radius:.5rem">'
                                    : '';

                                return new HtmlString($link.$preview);
                            }),
                    ]),

                /*
                | ⛔ الحالةُ كانت حقلاً قابلاً للكتابة — وهو بابٌ ثانٍ للقرارِ
                | يتخطّى الإجراءَ بأكملِه، وقد استُعمِلَ فعلاً.
                |
                | حفظُ النموذجِ يكتبُ `status = approved` عبرَ `handleRecordUpdate`،
                | فلا `PaymentApproved` ولا حركةَ دفعٍ ولا `approved_by` ولا صفَّ
                | تدقيقٍ ولا إشعار — وستّةُ مستمعينَ معلَّقينَ على ذلك الحدثِ لا
                | يعملُ منها واحد: التسجيلُ وسكُّ الأرصدةِ وتفعيلُ الاشتراكِ وتسليمُ
                | المتجرِ وإتمامُ الإحالة.
                |
                | قِيسَ على الإنتاج 2026-09-04: الطلبُ ٧ (‏٤٨٠ ر.ق · أربعةُ أرصدة)
                | حالتُه «معتمَد» و`approved_by` فارغٌ و`approved_at` فارغٌ وبلا
                | حركةِ دفعٍ واحدة، و`purchased_credits = 0`. الطالبُ دفعَ ولم يأخذْ
                | شيئاً، والطلبُ يقرأُ «معتمَد» فلا يعودُ إليه أحدٌ أبداً — والتوقيعُ
                | الثلاثيُّ (حالةٌ معتمَدةٌ بلا معتمِدٍ ولا وقت) هو ما يميّزُ هذا
                | الكتبَ عن قرارٍ حقيقيّ، لأنّ {@see ApproveOrder} يكتبُ الثلاثةَ في
                | جملةٍ واحدة.
                |
                | ⚠️ `Placeholder` لا `Select::disabled()`: المعطَّلُ يعتمدُ على
                | ألّا يُرطِّبَ Filament قيمتَه، والنائبُ لا يحملُ مفتاحاً في
                | الحمولةِ أصلاً. القرارُ بالزرِّ وحدَه.
                */
                Section::make('القرار')
                    ->description('القرارُ بالزرَّينِ أعلى الصفحة — هما ما يُنشئُ التسجيلَ أو يسكُّ الأرصدة. الحالةُ هنا للقراءةِ فقط.')
                    ->schema([
                        Placeholder::make('status')
                            ->label('الحالة')
                            ->content(fn (?Order $record): string => $record === null
                                ? '—'
                                : OrderStatus::labelFor($record->status)),
                    ]),
            ]);
    }

    /**
     * القرارُ نفسُه، معروضاً على السطحَين — الجدولِ وصفحةِ الطلب.
     *
     * ⚠️ THE APPROVAL LIVES HERE, AND WITHOUT THESE TWO BUTTONS THE MOVE IS A
     * REMOVAL. `payments.approve` left the teacher's role on 2026-09-03 and the
     * teacher's own «الطلبات» screen lost its buttons with it — a permission with
     * no surface behind it is every pending transfer on the platform sitting
     * unanswered, this repository's «إذنٌ لا يصلُه رابط» wearing money.
     *
     * ⚠️ `authorize()`, NEVER A RE-DERIVED `can()`. `OrderPolicy` is where the
     * two questions are answered — a credit purchase asks
     * `billing.purchase.approve` and a course order asks `payments.approve` — and
     * a second spelling here would put one answer on the button and another at
     * the Action.
     *
     * ⚠️ AND THE ACTIONS ARE THE ACTIONS. `ApproveOrder` writes the
     * conditional-UPDATE claim, the enrolment, the audit row and the
     * notification; a status written from a panel skips all four silently.
     * Filament must produce exactly what the endpoint produces —
     * `TeacherApplicationResource`'s own standard. **قِيسَ على الإنتاج
     * 2026-09-04**: حقلُ الحالةِ في النموذجِ كان الطريقَ الثاني، وسلَكَه موظّفٌ
     * فعلاً — فبقيَ الطالبُ بلا أرصدةٍ دفعَ ثمنَها. الحقلُ صارَ نائباً للقراءةِ
     * لا حقلاً يُحفَظ.
     *
     * ⚠️ AND THE PREDICATE IS `isPending()`, THE MODEL'S OWN SPELLING —
     * `status === 'pending'` was a SECOND one, and it hid both buttons on exactly
     * the orders that have a receipt: {@see UploadPaymentReceipt} stamps
     * `under_review`, which `ApproveOrder`'s claim accepts and this test did not.
     * So the order an officer is most likely to be deciding was the one with no
     * button on it — which is how the writable status field came to be used at
     * all.
     *
     * ⚠️ ومعروضةٌ على صفحةِ الطلبِ كذلك ({@see EditOrder::getHeaderActions()}):
     * الإيصالُ هناك، وتعليقُه يقولُ «افتحْها وطابقِ المبلغَ قبلَ الاعتماد» — وشاشةٌ
     * تطلبُ المطابقةَ ولا تحملُ زرَّ القرارِ تدفعُ القارئَ إلى أيِّ بابٍ آخرَ يجدُه.
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('اعتمد')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Order $record): bool => $record->isPending())
            ->authorize('approve')
                // A confirmation, because approving mints an entitlement and
                // nothing here takes it back.
            ->requiresConfirmation()
            ->modalHeading('اعتماد التحويل')
            ->modalDescription('يُنشئ هذا التسجيل أو الرصيد فوراً. افتحِ الإيصال وطابقِ المبلغ قبل الاعتماد.')
            ->action(function (Order $record): void {
                if (self::refusedForTwoFactor()) {
                    return;
                }

                app(ApproveOrder::class)->handle($record, self::actor(), request()->ip(), request()->userAgent());

                Notification::make()->success()->title('اعتُمد الطلب')->send();
            });
    }

    /** The refusal, beside its twin — see {@see approveAction()}. */
    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('ارفض')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => $record->isPending())
            ->authorize('reject')
            ->requiresConfirmation()
            ->modalHeading('رفض التحويل')
            ->schema([
                // ⚠️ REQUIRED, because `RejectOrder` takes it and the
                // buyer reads it: a refusal the student cannot act on is
                // a refusal they ask about by message instead.
                Textarea::make('reason')
                    ->label('السبب')
                    ->required()
                    ->maxLength(500)
                    ->helperText('يصل هذا النصّ إلى المشتري، فاكتبْ ما يمكنه التصرّف بناءً عليه.'),
            ])
            ->action(function (Order $record, array $data): void {
                if (self::refusedForTwoFactor()) {
                    return;
                }

                app(RejectOrder::class)->handle(
                    $record,
                    self::actor(),
                    (string) $data['reason'],
                    request()->ip(),
                    request()->userAgent(),
                );

                Notification::make()->warning()->title('رُفض الطلب')->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('course.title')
                    ->label('الكورس')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                // ⚠️ بلا بحثٍ ولا ترتيب: `name` سِمةٌ محسوبةٌ لا عمود. {@see CourseResource}
                TextColumn::make('user.name')
                    ->label('اسم المشتري')
                    ->placeholder('—'),
                TextColumn::make('user.email')
                    ->label('بريد المشتري')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('نُسخ البريد'),
                TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (
                        $state instanceof OrderKind ? $state : OrderKind::tryFrom(is_scalar($state) ? (string) $state : '')
                    )?->label() ?? (is_scalar($state) ? (string) $state : '—')),
                TextColumn::make('amount_minor')
                    ->label('المبلغ')
                    ->money(fn (Order $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => OrderStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'under_review' => 'info',
                        'rejected', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('approver.email')
                    ->label('اعتمده')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(OrderStatus::options()),
            ])
            ->actions([
                EditAction::make(),
                self::approveAction(),
                self::rejectAction(),
            ]);
    }

    /**
     * ⚠️ The panel is session-authenticated, so the actor is never null here —
     * but the Actions type it as `User` and a `??` returning something else would
     * put the wrong name in the audit row that records who decided.
     */
    /**
     * ⚠️ THE PANEL NEVER PASSED THROUGH `2fa.required`, AND THAT WAS THE HOLE.
     *
     * `/orders/{uuid}/approve` and `/reject` each carry that middleware in their
     * own route definition — but `/admin` is session-authenticated and reaches
     * the same Actions without touching it. So moving the approval here would
     * have moved it out from behind the second factor, on the one decision that
     * mints an entitlement.
     *
     * ⚠️ AND IT IS ASKED HERE RATHER THAN ON `canViewAny()`. This repository's
     * rule is «route by route, never a group»: refusing the whole panel would
     * lock every teacher out of screens that were never sensitive, and would grow
     * an exception list nobody prunes.
     *
     * The sentence is `TwoFactorMandate`'s, not one written here — the API
     * answers with the same words for the same operation.
     */
    private static function refusedForTwoFactor(): bool
    {
        $refusal = TwoFactorMandate::refusalFor(self::actor());

        if ($refusal === null) {
            return false;
        }

        // Told, never hidden: a control that vanishes reads as a broken screen,
        // and the reader cannot act on what they are not shown.
        Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

        return true;
    }

    private static function actor(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('لا مستخدم في الجلسة.');
        }

        return $user;
    }

    /**
     * The credit-purchase cut, repeated here because a TABLE takes no row policy.
     *
     * ⚠️ `OrderPolicy::view()` refuses a credit purchase to anyone without
     * `BILLING_PURCHASE_APPROVE` — a purchase is a sale between the student and the
     * PLATFORM (Q-4), so a teacher holding `ORDERS_VIEW_ALL` may read their own
     * course orders and none of the platform's sales. A Filament list never calls
     * `view()`, so without this the panel handed the teacher every credit total,
     * and two totals across two package sizes solve for the platform's constants —
     * the one inference `billing.collection.view` exists to hold.
     *
     * `OrderController::index()` makes the identical cut in the identical words.
     * Two places, because a list and a record are two different questions, and the
     * one that has no policy behind it is the one that gets forgotten.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Auth::user();

        // ⚠️ `whereIn`, NOT `!= Credits`. The denylist this replaces was correct
        // over an enum of two cases and grew a hole at four: spec 011's store
        // sales and subscriptions would have landed on this table for anyone
        // without the platform permission, which on a panel that admits
        // `assistant-teacher` by role name is the FR-003 breach `viewAny()` was
        // fixed for. One spelling, in the enum — see `teacherListedValues()`.
        if ($user instanceof User && ! $user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            $query->whereIn('kind', OrderKind::teacherListedValues());
        } else {
            /*
            | ⚠️ 024 — THE FIFTH LAYER OF THE SAME DEFECT, AND THE LIST IS WHERE
            | IT LOOKS LIKE NOTHING IS WRONG.
            |
            | The kind cut above was already right; the workspace was not. This
            | query runs through `BelongsToWorkspace`, and a platform officer's
            | context falls back to `users.last_workspace_id` like everybody
            | else's — so an officer who also owns a workspace saw that
            | workspace's orders and no others, on the one screen whose whole
            | purpose is approving sales across every teacher. No error, no empty
            | state, just a short list that reads as a quiet week.
            |
            | Only for the holder of the platform permission, and the row-level
            | answer is unchanged: `OrderPolicy::view()` still decides what may be
            | opened.
            */
            // `withoutGlobalScope(WorkspaceScope::class)` and not the model's
            // `withoutWorkspaceScope()` helper: Filament's parent hands back a
            // `Builder<Model>`, on which the model's local scope is not typed.
            // The two are the same call — see `BelongsToWorkspace::scopeWithoutWorkspaceScope()`.
            $query->withoutGlobalScope(WorkspaceScope::class);
        }

        /*
        | ⚠️ THE BYPASS IS PER MODEL, AND THE EAGER LOAD IS A SECOND QUERY.
        | Dropping the scope above only frees the OUTER read; `->with('course')`
        | runs its own query, on which `Course`'s `BelongsToWorkspace` scope
        | applies afresh — so every order outside the officer's fallback
        | workspace came back with a null course and rendered «—», with no error
        | anywhere. That is the fifth layer of the 024 defect, and it was still
        | here: the four fixed layers all raised a status code, and this one
        | raises nothing at all.
        |
        | `user` and `approver` need no bypass: `users` is platform-owned and
        | carries no workspace scope.
        */
        return $query->with([
            'course' => fn ($relation) => $relation->withoutGlobalScope(WorkspaceScope::class),
            'user',
            'approver',
        ]);
    }

    public static function getRelations(): array
    {
        return [
            OrderResource\RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
