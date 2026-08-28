<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\UserSecuritySettings;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\PlatformStaffDirectory;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\AssistantForbiddenPermissions;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\HasUuid;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * Cast in casts() below; restated here because Larastan reads the column type
 * from the migration, where platform_role is a plain string.
 *
 * @property PlatformRole|null $platform_role
 * @property string|null $phone
 * @property string|null $country
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable {
        // Spec 010 — the financial wall overrides this method; the alias is how
        // the overriding version calls the one it is wrapping. See the note on
        // hasPermissionTo() below for why the guard could not be a `Gate::before`.
        HasRoles::hasPermissionTo as private spatieHasPermissionTo;
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
    ];

    protected $guarded = [
        'id',
        'is_super_admin',
        'last_workspace_id',
        // Platform role decides which product a user sees; it is set only by the
        // registration Actions, never by a mass-assigned payload.
        'platform_role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'is_super_admin',
        'platform_role',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'platform_role' => PlatformRole::class,
        ];
    }

    /**
     * The workspaces this user belongs to.
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The marketplace teacher profile, if this user applied to teach.
     *
     * @return HasOne<TeacherProfile, $this>
     */
    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    /**
     * The user's notification feed.
     *
     * Overrides the relation Notifiable ships, which points at Laravel's own
     * notifications schema — a table this application replaced (spec 003). The
     * trait itself stays: Laravel's password-reset and email-verification mails
     * go through notify(), and a reset link delivered in-app would be unreachable
     * by definition, since the person asking for it cannot sign in to read it.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_user_id')->latest('id');
    }

    /**
     * Guardians and parents linked to this user as the student.
     *
     * @return HasMany<ParentStudentRelation, $this>
     */
    public function guardianRelations(): HasMany
    {
        return $this->hasMany(ParentStudentRelation::class, 'student_user_id');
    }

    /**
     * Students this user is a guardian or parent of.
     *
     * @return HasMany<ParentStudentRelation, $this>
     */
    public function wardRelations(): HasMany
    {
        return $this->hasMany(ParentStudentRelation::class, 'guardian_user_id');
    }

    /**
     * What is true of this user as a student, and of no other role.
     *
     * Its own table rather than columns here: `users` is read on every
     * authenticated request, and a grade level sitting on it invites every screen
     * to read one for an account that may not be a student at all.
     *
     * @return HasOne<StudentProfile, $this>
     */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** @return HasOne<UserSecuritySettings, $this> */
    public function securitySettings(): HasOne
    {
        return $this->hasOne(UserSecuritySettings::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /**
     * Convenience accessor for the user's full name.
     */
    public function getNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /*
    | ⚠️ بابُ لوحةِ `/admin` — وغيابُ هذه الواجهةِ كان يرفضُ **كلَّ** تسجيلِ دخولٍ
    | في الإنتاج. `Filament\Http\Middleware\Authenticate` يكتب:
    |
    |     abort_if($user instanceof FilamentUser
    |         ? (! $user->canAccessPanel($panel))
    |         : (config('app.env') !== 'local'), 403);
    |
    | فبلا الواجهةِ يصيرُ الشرطُ «البيئةُ ليست local» — صحيحاً دائماً على الخادم.
    | والفحصُ الدخانيُّ لا يراه: `/admin` يرُدُّ ٣٠٢ إلى شاشةِ الدخولِ **قبلَ** أيِّ
    | مصادقة، فالرفضُ لا يظهرُ إلّا لمن يملكُ كلمةَ مرورٍ صحيحةً ويُطرَدُ بها.
    | والتطويرُ المحلّيُّ عمياءُ عنه بالتعريف: `APP_ENV=local` هو الاستثناءُ نفسُه.
    |
    | ⚠️ والحكمُ مكتوبٌ **هنا وحدَه**: `EnsureFilamentAccess` كان يحملُ نسخةً ثانيةً
    | منه، وهجاءان لسؤالٍ واحدٍ يضعان جواباً على الشاشةِ وآخرَ عندَ الباب — العيبُ
    | الذي دفعَ ثمنَه `BookingEligibility` و`ListLeaderboardScopes` كلٌّ مرّةً.
    */
    public function canAccessPanel(Panel $panel): bool
    {
        // لوحةٌ واحدةٌ في هذا المنتَج، فالحكمُ لا يتفرّعُ على `$panel`. لوحةٌ
        // ثانيةٌ يوماً ما تتفرّعُ هنا على `$panel->getId()` ولا شيءَ غيرِه.
        return $this->mayAccessAdminPanel();
    }

    public function mayAccessAdminPanel(): bool
    {
        /*
        | ⚠️ مديرُ المنصّةِ وحدَه. كانت تقبلُ `tenant-owner` و`teacher` و
        | `assistant-teacher` أيضاً — ولا حاجةَ لهم بها: للمدرّسِ سطحُه الكاملُ في
        | `/manage/*` (المواد، التصحيح، المحاسبة، المساعدون، التجميد…)، و`/admin`
        | لوحةُ المنصّةِ لا لوحةُ المدرّس.
        |
        | وكلُّ بابٍ زائدٍ على اللوحةِ يُدفَعُ ثمنُه مرّتَين: `OrderResource` سبقَ
        | أن سلّمَ مساعِداً بريدَ كلِّ طالبٍ والمبلغَ الذي دفعَه، لأنّ قائمةَ
        | Filament لا تستدعي سياسةَ الصفِّ أصلاً — عيبٌ لا يوجدُ إن لم يكنِ
        | المساعدُ يدخلُ اللوحةَ من الأساس.
        */
        if ($this->isSuperAdmin()) {
            return true;
        }

        /*
        | ⚠️ وموظّفو المنصّةِ كذلك — وهذا ليس توسيعاً للقاعدةِ بل تطبيقُها.
        | `platform_staff` جدولٌ موجودٌ لأنّ spatie لا يستطيعُ التعبيرَ عن دورٍ
        | بلا `team_id`، وحاملُه — مسؤولُ المالية مثلاً — **شاشاتُه الوحيدةُ في
        | هذه اللوحة** (تدقيقُ التسويات، أرصدةُ المنصّة). حصرُ الدخولِ في
        | `is_super_admin` وحدَه يُغلِقُ اللوحةَ في وجهِ من بُنِيَت له.
        |
        | وليس دوراً في مساحةِ عمل: `rolesFor()` تقرأُ الجدولَ الذي لا
        | `team_id` فيه، فمالكُ المساحةِ والمدرّسُ والمساعدُ لا يمرّون من هنا.
        */
        return app(PlatformStaffDirectory::class)->rolesFor($this) !== [];
    }

    /**
     * Spec 010 · FR-003 · SC-001 — an assistant holds no financial permission,
     * whatever role granted it.
     *
     * ⚠️ HERE, AND NOT IN A `Gate::before`, AND THE DESIGN SAID OTHERWISE UNTIL A
     * TEST MEASURED IT. spatie registers a before-callback of its own
     * (`PermissionRegistrar::registerPermissions()`) that answers `true` for any
     * permission the user holds — and `Gate::callBeforeCallbacks()` returns the
     * FIRST non-null answer. spatie's is registered while the Gate is being
     * resolved, which is before any module provider has booted, so a wall written
     * as a `Gate::before` is consulted only for permissions the user does not have
     * and refuses nothing at all. `Gate::after` cannot rescue it either: the merge
     * is `$result ??= $afterResult`, so an after-callback can fill in a null and
     * can never overturn a `true`. The five routes went on answering 200 and every
     * assertion about the payload was still correct.
     *
     * ⚠️ AND NOT A GUARD ON `Role` EITHER, which is the other obvious placement.
     * `Tenancy\Models\Role::refusePlatformPermissions()` keys on `team_id === null`
     * and every permission in this set is tenant-side, held legitimately by the
     * teacher's own role — so the owner invents a role under any other name, ticks
     * `payments.approve` onto it from the roles screen they already have, and the
     * grant is a perfectly ordinary write. The refusal has to sit where the
     * permission is READ.
     *
     * ⚠️ KEYED ON THE ASSIGNMENT ROW, NEVER ON A ROLE NAME. The row is what says
     * «this person is on the teacher's team here»; a role name is what the owner
     * controls, so keying on `assistant-teacher` is walled off by renaming.
     *
     * ⚠️ AND IT IS ASKED ONLY FOR NAMES IN THE FINANCIAL SET, which is an `isset`
     * on a memoised map of about twenty keys. This method is called on every
     * permission check in the product — once per row of a Filament table — so
     * anything that touched the database before that test would be a query per
     * check.
     *
     * @param  string|int|Permission  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if (is_string($permission)
            && ! $this->isSuperAdmin()
            && AssistantForbiddenPermissions::refuses($permission)
        ) {
            $workspaceId = app(WorkspaceContext::class)->id();

            if ($workspaceId !== null
                && app(AssistantScopeDirectory::class)->isAssistantIn($this, $workspaceId)
            ) {
                return false;
            }
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->securitySettings?->isConfirmed() ?? false;
    }

    /*
    |--------------------------------------------------------------------------
    | Filament app-authentication contract
    |--------------------------------------------------------------------------
    |
    | Implemented by hand rather than via Filament's traits, which read and write
    | two columns on this table. The contract itself says nothing about where the
    | secret lives — only that these five methods exist — so honouring it while
    | storing on user_security_settings costs eight lines and buys one enrolment
    | that works in both the panel and the API.
    */

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->securitySettings?->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->securitySettings()->updateOrCreate([], ['app_authentication_secret' => $secret]);
        $this->unsetRelation('securitySettings');
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<int, string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->securitySettings?->app_authentication_recovery_codes;
    }

    /** @param  array<int, string>|null  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->securitySettings()->updateOrCreate([], ['app_authentication_recovery_codes' => $codes]);
        $this->unsetRelation('securitySettings');
    }
}
