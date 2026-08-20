<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources;

use App\Models\User;
use App\Modules\Tenancy\Filament\Resources\PlatformStaffResource\Pages;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Support\PlatformStaffDirectory;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Naming the people who may act for the PLATFORM.
 *
 * ⚠️ THIS IS THE SCREEN spatie COULD NOT GIVE US. `model_has_roles` puts
 * `team_id` in its primary key and forbids NULL, so `finance-admin` — seeded and
 * correct since 006 — could be given to nobody. Delegation therefore names a
 * PERSON in a table of ours, and `Gate::before` turns the standing into the
 * permissions of the teamless role.
 *
 * ⚠️ AND WHAT IS GRANTED HERE IS A ROLE, NEVER A PERMISSION. The set behind
 * `finance-admin` lives in `RolePermissionMatrix`, in code, reviewed like code.
 * A screen that could add one permission to it is a screen that mints a second
 * super admin quietly — which is exactly why the role screen refuses platform
 * roles and this one refuses individual permissions.
 *
 * ⚠️ `reason` IS REQUIRED, and that is the point of the row as much as the
 * grant is. The question an auditor asks about a payment approval is not "was it
 * approved" but "who was allowed to approve it, and who allowed them". A
 * standing with no reason answers half of that.
 */
class PlatformStaffResource extends Resource
{
    protected static ?string $model = PlatformStaff::class;

    public static function getNavigationLabel(): string
    {
        return 'صلاحيات المنصّة';
    }

    public static function getModelLabel(): string
    {
        return 'تفويض منصّة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'تفويضات المنصّة';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('الشخص')
                /*
                | ⚠️ `email`, NOT `name` — `users` HAS NO `name` COLUMN. It is an
                | accessor over `first_name` and `last_name`, so a relationship
                | title of `name` compiles to `select users.name` and the page is
                | a 500. The render test caught it on its first run; every
                | `canViewAny()` assertion in this file had passed while the
                | screen could not open at all.
                |
                | The email is also the better handle here: two people share a
                | name, and the person appointing a finance officer is choosing an
                | account, not a person with a nice name.
                */
                ->relationship('user', 'email')
                ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->name.' — '.$record->email)
                ->searchable(['first_name', 'last_name', 'email'])
                ->preload()
                ->required()
                ->helperText('يبحث بالاسم. التفويض يسري في كل مساحات العمل، لا في واحدة.'),

            Select::make('role')
                ->label('الدور')
                ->required()
                ->options(self::assignableRoles())
                // super-admin is absent: it is the `users.is_super_admin` column,
                // not a standing — and a screen that could grant it would be a
                // screen that hands over the platform in one click.
                ->helperText('الصلاحيات خلف كل دور مكتوبة في الكود، ولا تُعدَّل من هنا.'),

            Textarea::make('reason')
                ->label('السبب')
                ->required()
                ->maxLength(500)
                ->helperText('يُقرأ لاحقاً في مراجعة: لماذا مُنح هذا الشخص هذه السلطة، ومتى.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                // Same reason: the accessor renders, but sorting or searching on
                // it would reach for a column that is not there.
                // The accessor, composed from the two columns that exist. The
                // column key is a real one so search and sort have something to
                // reach for; the label is the person's full name.
                TextColumn::make('user.first_name')->label('الشخص')
                    ->formatStateUsing(fn (PlatformStaff $record): string => $record->user->name)
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('user.email')->label('البريد')->searchable()->toggleable(),
                TextColumn::make('role')->label('الدور')->badge()
                    ->formatStateUsing(fn (string $state): string => self::assignableRoles()[$state] ?? $state),
                TextColumn::make('assigner.first_name')->label('فوّضه')->placeholder('—')
                    ->formatStateUsing(fn (PlatformStaff $record): string => $record->assigner->name),
                TextColumn::make('reason')->label('السبب')->wrap()->limit(80),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
            ])
            /*
            | ⚠️ THE REVOKE, WITHOUT WHICH THIS SCREEN ONLY EVER ADDS. Filament
            | puts no row action on a table by itself, and the policy allowing a
            | delete is not a button — a standing granted by mistake would have
            | needed tinker to undo. Deleting rather than editing, because
            | rewriting who appointed whom and why is rewriting the record.
            */
            ->recordActions([
                DeleteAction::make()
                    ->label('سحب التفويض')
                    ->modalHeading('سحب تفويض المنصّة')
                    ->modalDescription('تسري الصلاحيات فوراً بعد السحب. لا يمسّ هذا حسابه ولا عضويّاته.')
                    ->after(fn (PlatformStaff $record) => self::forgetStanding($record)),
            ]);
    }

    /**
     * The platform roles a person can be given.
     *
     * Derived from `Roles::platformRoles()` minus the super admin, so a third
     * platform role added tomorrow appears here without anybody remembering to
     * add it — and the permission set behind each is shown as a count rather than
     * a list, because the list is code and a screen that renders it invites the
     * question "can I change it".
     *
     * @return array<string, string>
     */
    private static function assignableRoles(): array
    {
        $matrix = RolePermissionMatrix::map();
        $options = [];

        foreach (Roles::platformRoles() as $role) {
            if ($role === Roles::SUPER_ADMIN) {
                continue;
            }

            $count = count($matrix[$role] ?? []);
            $options[$role] = match ($role) {
                Roles::FINANCE_ADMIN => 'مسؤول مالي',
                // Spec 013. Without this arm the role renders as its raw slug on
                // an Arabic-only panel — and a role nobody can identify is a role
                // nobody grants, which leaves the super admin as the only account
                // able to execute a rights request.
                Roles::COMPLIANCE_OFFICER => 'مسؤول حماية البيانات',
                default => $role,
            }." ({$count} صلاحية)";
        }

        return $options;
    }

    public static function canEdit(Model $record): bool
    {
        // A standing is granted or revoked, never edited: rewriting who appointed
        // whom and why is rewriting the record itself.
        return false;
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlatformStaff::route('/'),
            'create' => Pages\CreatePlatformStaff::route('/create'),
        ];
    }

    /**
     * Forget what the directory remembered about this person.
     *
     * The memo is filled per request and the grant happens inside one, so without
     * this the page rendered straight afterwards still answers from before the
     * grant — and the admin who just made someone a finance officer sees a screen
     * saying they are not.
     */
    public static function forgetStanding(PlatformStaff $staff): void
    {
        // `user_id` is NOT NULL with a foreign key behind it, so there is no
        // null branch to write here — and a branch that can never run is a
        // branch the next reader spends time working out.
        app(PlatformStaffDirectory::class)->forget($staff->user);
    }
}
