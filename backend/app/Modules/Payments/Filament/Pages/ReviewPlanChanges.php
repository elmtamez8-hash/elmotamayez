<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Pages;

use App\Models\User;
use App\Modules\Payments\Actions\DecidePlanChange;
use App\Modules\Payments\Enums\PlanChangeStatus;
use App\Modules\Payments\Models\PlanChangeRequest;
use App\Modules\Payments\Support\PlanShape;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The officer's queue for «سعّرتم باقتي وأنا عايز أغيّرها» (٠٣٦).
 *
 * ⛔ IT IS A QUEUE, AND A QUEUE IS BOUNDED BY WHAT IS STILL OPEN. `PlanResource`
 * already carries a badge for plans awaiting a price; this is the other half of
 * the same job, and putting it inside that table as a filter would hide a
 * decision somebody is waiting on inside a list of a hundred rows.
 *
 * ⛔ AND THE READ DECLARES `withoutWorkspaceScope()`. `plan_change_requests` is
 * tenant-owned, and `WorkspaceContext::id()` falls back to
 * `users.last_workspace_id` for a platform officer exactly as it does for
 * anybody else — so a scoped query silently answers about whichever workspace
 * the officer happens to own, on a screen whose whole job is to be
 * platform-wide. That is ٠٢٤'s fifth layer: no error, no status code, just a
 * short list that reads as a quiet week.
 *
 * ⚠️ AND THE PERMISSION IS ASKED ON THE PAGE, not inherited from the navigation.
 * A filtered menu shapes one request and not the next — a slug is typed as
 * easily as it is clicked.
 */
class ReviewPlanChanges extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.review-plan-changes';

    protected static ?string $slug = 'plan-change-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 26;

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::PLANS_PRICE) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'طلبات تعديل الباقات';
    }

    public function getTitle(): string
    {
        return 'طلبات تعديل الباقات';
    }

    /**
     * ⚠️ THE BADGE COUNTS WHAT IS WAITING, and it is the same scope bypass as the
     * table. Counted through the scope it reads zero for every officer whose own
     * workspace has no requests — which is every officer — so the queue would
     * never announce itself.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $pending = PlanChangeRequest::query()->withoutWorkspaceScope()->pending()->count();

        return $pending === 0 ? null : (string) $pending;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PlanChangeRequest::query()
                ->withoutWorkspaceScope()
                /*
                | ⚠️ AND THE EAGER LOADS CARRY THE BYPASS TOO. `->with('plan')`
                | runs Plan's own global scope afresh, so every request outside
                | the officer's fallback workspace would render a blank plan name
                | beside a decision they are being asked to take.
                */
                ->with([
                    'plan' => fn ($plan) => $plan->withoutGlobalScope(WorkspaceScope::class),
                    'requester',
                ])
                ->orderBy('status')
                ->orderByDesc('requested_at'))
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('لا طلبات تعديل')
            ->emptyStateDescription('سيظهر هنا كلُّ طلبٍ من مدرّسٍ لتغييرِ باقةٍ سعّرتها المنصّة.')
            ->columns([
                TextColumn::make('plan.title')->label('الباقة')->placeholder('—')->wrap(),
                TextColumn::make('requester.name')->label('المدرّس')->placeholder('—'),
                /*
                | ⚠️ FROM THE REQUEST'S OWN SNAPSHOT, NEVER FROM THE PLAN ROW. The
                | approval retires that row and writes a new one, so a column that
                | read the live plan would describe something else entirely the
                | moment anybody scrolled back.
                */
                TextColumn::make('from')->label('كانت')->placeholder('—')
                    ->state(fn (PlanChangeRequest $record): string => self::sideOf(
                        $record->current_duration_days,
                        $record->current_session_count,
                        $record->current_coverage_type->label(),
                        $record->current_price_minor,
                    )),
                TextColumn::make('to')->label('المطلوب')->placeholder('—')
                    ->state(fn (PlanChangeRequest $record): string => self::sideOf(
                        $record->requested_duration_days,
                        $record->requested_session_count,
                        $record->requested_coverage_type->label(),
                        $record->requested_price_minor,
                    )),
                TextColumn::make('reason')->label('سبب المدرّس')->placeholder('—')->wrap()->limit(120),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (PlanChangeStatus $state): string => $state->label())
                    ->color(fn (PlanChangeStatus $state): string => match ($state) {
                        PlanChangeStatus::Pending => 'warning',
                        PlanChangeStatus::Approved => 'success',
                        PlanChangeStatus::Rejected => 'danger',
                    }),
                TextColumn::make('requested_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                $this->decision('approve', 'موافقة', 'success', true),
                $this->decision('reject', 'رفض', 'danger', false),
            ]);
    }

    /**
     * ⚠️ BOTH BUTTONS ARE THE SAME ACTION WITH A BOOLEAN, and both are
     * `->visible(pending)`. A decided request that still offers a button is a
     * second decision waiting to be taken by whoever opens the queue next.
     *
     * ⚠️ AND THE REJECTION'S REASON IS REQUIRED. «مرفوض» with no sentence leaves
     * the teacher with a plan they cannot change and no idea what to ask for
     * instead — which is a support ticket with extra steps.
     */
    private function decision(string $name, string $label, string $colour, bool $approve): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($colour)
            ->requiresConfirmation()
            ->visible(fn (PlanChangeRequest $record): bool => $record->status === PlanChangeStatus::Pending)
            ->schema([
                Textarea::make('reason')
                    ->label($approve ? 'ملاحظة للمدرّس (اختياريّة)' : 'سبب الرفض')
                    ->required(! $approve)
                    ->maxLength(500),
            ])
            ->action(function (PlanChangeRequest $record, array $data) use ($approve): void {
                $officer = Auth::user();

                if (! $officer instanceof User) {
                    return;
                }

                try {
                    app(DecidePlanChange::class)->handle(
                        $record,
                        $officer,
                        $approve,
                        is_string($data['reason'] ?? null) ? $data['reason'] : null,
                    );
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title($approve
                    ? 'كُتبت الباقة الجديدة وأُوقفت القديمة عن البيع.'
                    : 'أُبلغ المدرّس بالرفض، والباقة كما هي.')->send();
            });
    }

    /** «١٢ حصّة · كورس واحد · ٣٠٠٫٠٠» — one side of the comparison. */
    private static function sideOf(?int $days, ?int $sessions, string $coverage, ?int $priceMinor): string
    {
        $parts = array_filter([
            PlanShape::describe($days, $sessions),
            $coverage,
            // ⚠️ «تنتظر التسعير» and not «٠٫٠٠»: a request that names no number is
            // a teacher leaving the price to the platform, which is the ordinary
            // arrangement — and a zero there reads as «free».
            $priceMinor === null ? 'تنتظر التسعير' : number_format($priceMinor / 100, 2),
        ]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
