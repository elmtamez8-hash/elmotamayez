<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources;

use App\Modules\Payments\Actions\CancelSubscription;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Filament\Resources\SubscriptionResource\Pages;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The platform's list of subscriptions, and the one place one can be undone.
 *
 * ⛔ **`CancelSubscription` WAS BUILT, TESTED, AND UNREACHABLE.** Its only
 * production entrance was `POST /admin/subscriptions/{uuid}/cancel`, which no
 * file under `frontend/src` calls — and there was no platform-wide LIST of
 * subscriptions anywhere either, so even by hand an officer had no way to find
 * the uuid. The product had no way to cancel a subscription and return its
 * money, while `ReversePayment → PaymentReversed → ReverseReferralAward +
 * ReevaluateOnReversal` sat live behind that door.
 *
 * ⚠️ «CANCEL» HERE MEANS «UNDO», NOT «DO NOT RENEW». Nothing in this product
 * auto-renews — one subscription is one order for one period — so a student who
 * simply wants no second month buys nothing and needs no button. What is left is
 * taking the purchase back, and taking a purchase back without returning the
 * money is a forfeiture. The Action stops access and reverses the capture
 * together; there is no proration, deliberately.
 *
 * ⚠️ `canViewAny()` IS THE PLATFORM PERMISSION AND NOT `SubscriptionPolicy::view`.
 * That policy admits the SUBSCRIBER and the selling teacher, both correctly, for
 * a single row over the API — and a Filament LIST never consults the row policy,
 * so either of them admitted here would read every subscription on the platform.
 * The discovery `OrderResource` and `PlanResource` have each already made.
 *
 * ⚠️ AND THE QUERY DECLARES `withoutWorkspaceScope()`. `WorkspaceContext::id()`
 * falls back to `users.last_workspace_id` for a platform officer exactly as for
 * anybody else, so a scoped list quietly shows one arbitrary teacher's
 * subscriptions as though they were the platform's — and passes its own test on
 * a single-workspace fixture.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 26;

    public static function getNavigationLabel(): string
    {
        return 'الاشتراكات';
    }

    public static function getModelLabel(): string
    {
        return 'اشتراك';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الاشتراكات';
    }

    /** @return Builder<Subscription> */
    public static function getEloquentQuery(): Builder
    {
        // Built from the model rather than from `parent::getEloquentQuery()`,
        // whose declared return type is a builder over the base `Model` and
        // therefore knows nothing of the trait's bypass.
        return Subscription::query()
            ->withoutWorkspaceScope()
            // ⚠️ `first_name,last_name` and never `name`: `users` has no such
            // column — it is an accessor over the two — so a constrained eager
            // load naming it renders every row as a blank. Six call sites across
            // four modules shipped that way once.
            ->with(['plan:id,title', 'workspace:id,name', 'student:id,first_name,last_name']);
    }

    public static function form(Schema $schema): Schema
    {
        // A subscription is bought, never authored. The only write this screen
        // offers is the cancellation below, which carries its own form.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('student.name')->label('الطالب')->searchable(['first_name', 'last_name']),
                TextColumn::make('workspace.name')->label('المدرّس')->searchable(),
                TextColumn::make('plan.title')->label('الباقة'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state): string => $state->label())
                    ->color(fn (SubscriptionStatus $state): string => match ($state) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::Cancelled => 'danger',
                        SubscriptionStatus::Expired => 'gray',
                    }),
                TextColumn::make('starts_on')->label('من')->date(),
                TextColumn::make('effective_ends_on')->label('إلى')->date()
                    // The effective end is what access actually reads; `ends_on`
                    // is the period that was bought and stops being the answer the
                    // moment a cancellation moves it.
                    ->description(fn (Subscription $record): ?string => $record->cancelled_at === null
                        ? null
                        : 'أُلغي '.$record->cancelled_at->diffForHumans()),
                TextColumn::make('price_minor')->label('المدفوع')
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? '—'
                        : number_format($state / 100, 2)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(fn (): array => collect(SubscriptionStatus::cases())
                        ->mapWithKeys(fn (SubscriptionStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                self::cancelAction(),
            ]);
    }

    /**
     * Undo a purchase: stop the access and reverse the capture, together.
     *
     * ⚠️ IT CALLS THE ACTION, NEVER THE COLUMNS. `CancelSubscription` claims the
     * row with a conditional UPDATE — two taps on this button would otherwise both
     * read `active`, both proceed, and both call `ReversePayment` against one
     * payment. Writing `status` here would be a second writer beside the claim.
     *
     * ⚠️ AND THE REASON IS REQUIRED, because the Action refuses a blank one and a
     * refusal arriving as a red toast after the click is a worse way to learn it.
     * The same rule `AdjustCredits` and `SetCreditLimit` carry: a nullable reason
     * column is one caller away from an audit trail of blanks.
     */
    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('إلغاء واسترداد')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            // Irreversible and it moves money. Filament's own confirmation is the
            // panel's spelling of the two-press arm the frontend uses.
            ->requiresConfirmation()
            ->modalHeading('إلغاء الاشتراك واسترداد قيمته')
            ->modalDescription('يتوقّف الوصول فوراً وتُعكَس الدفعة كاملةً. لا استرداد جزئيّ — '
                .'إن أردتَ الإبقاء على جزء من المبلغ فاستعمل قيداً في الدفتر بدلَ هذا الزرّ.')
            ->schema([
                Textarea::make('reason')
                    ->label('السبب')
                    ->required()
                    ->maxLength(255)
                    ->helperText('يُسجَّل في سجلّ التدقيق المالي باسمك.'),
            ])
            // Only a live one can be undone, and only by somebody the policy
            // admits — the same question the API route asks, asked once.
            ->visible(fn (Subscription $record): bool => $record->status === SubscriptionStatus::Active
                && Gate::allows('cancel', $record))
            /*
            | ⚠️ THE PARAMETER IS NOT NAMED `$action`. Filament resolves a closure's
            | arguments by NAME first and injects its own `Action` instance into
            | one called that — so the type hint is ignored and the call fails at
            | run time with a TypeError, on the one button that moves money.
            */
            ->action(function (Subscription $record, array $data, CancelSubscription $cancel): void {
                try {
                    $cancel->handle($record, (string) $data['reason']);
                } catch (DomainException $e) {
                    // «Already cancelled» is the ordinary race, not an error page:
                    // two officers on the same row, or a tab left open since the
                    // sweep expired it.
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('أُلغي الاشتراك وعُكِست دفعته')->send();
            });
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
        ];
    }

    /** See the class docblock: the platform permission, not the row policy. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::BILLING_PURCHASE_APPROVE) === true;
    }

    /** A subscription is bought by a student. Never created here. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * ⚠️ Repeated on the Resource because `BasePolicy::before()` waves a super
     * admin past every policy method, and a super admin is exactly who stands at
     * this screen. Deleting the row would take away the student's record of what
     * they bought; cancelling is the act, and it keeps the row.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }
}
