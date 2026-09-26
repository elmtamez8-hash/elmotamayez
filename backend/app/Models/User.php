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
use App\Modules\Tenancy\Support\Roles;
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
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
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
    /**
     * @use HasFactory<UserFactory>
     * @use HasApiTokens<PersonalAccessToken|TransientToken>
     *
     * ⚠️ THE UNION IS THE TRUTH, and pinning it to `PersonalAccessToken` alone
     * is what let five `?->getKey()` call sites past the analyser and into a
     * production 500. See {@see self::currentTokenId()}.
     */
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
     * Whether this account teaches on the platform — and therefore never buys.
     *
     * ⚠️ THE PIVOT **ROLE**, NEVER MERE MEMBERSHIP — and this file said the
     * opposite until it was checked against a real database on 2026-09-08.
     * `CLAUDE.md` states that a student is a member of no workspace at all, and
     * that is true of a student who registered themselves; it is NOT true of the
     * ones a teacher or a seeder puts in a workspace, and the development
     * database held **six** `workspace_members` rows with `role = student`. A
     * predicate of «belongs to any workspace» would therefore have refused a
     * purchase to real students — the mirror of the bug it was written to fix,
     * and far worse, because it takes money-making away rather than a stray row.
     *
     * ⚠️ ASKED IN THE NEGATIVE, so the safe direction is refusal. A workspace
     * role this product has not seen (a teacher inventing one from `/admin`)
     * counts as teaching, and the worst case is a teacher-side account being
     * told it cannot buy — visible, reportable, and reversible. The positive
     * spelling («role is one of these three») fails the other way: a renamed
     * role reopens the door silently, which is the failure this repository
     * records under «a rule written against a role NAME».
     *
     * ⚠️ AND IT IS ASKED ABOUT THE STUDENT, NEVER THE CALLER. A guardian buying
     * for their child is the ordinary case, and the person who lands in
     * `enrollments` is the child — a guard on `currentUser()` would refuse the
     * wrong person and let the real one through.
     *
     * ⚠️ SINCE 2026-09-26 A STUDENT OR PARENT ACCOUNT CANNOT ACQUIRE A STAFF ROW
     * (`Tenancy\Support\StaffAccounts`, asked at the invitation, its acceptance
     * and a role change), so for an account that declared itself a learner this
     * answers false by construction — the person who also teaches holds a second
     * account under another email. It stays a pivot query all the same: rows
     * written before the rule, and the null-`platform_role` accounts the rule
     * cannot classify, are exactly the ones it still has to answer about.
     */
    public function teachesOnPlatform(): bool
    {
        return $this->workspaces()
            ->wherePivot('role', '!=', Roles::STUDENT)
            ->exists();
    }

    /**
     * Whether this person is THE TEACHER of a workspace — the one who decides
     * whether its courses are public or private (owner decision 2026-09-26).
     *
     * ⛔ NOT «may edit the course». An assistant holds `courses.update` and keeps
     * editing content; whether a course is shown to the whole marketplace is the
     * teacher's call, and it is asked here rather than through a permission so
     * the roles screen cannot hand it to an assistant by ticking a box.
     *
     * ⚠️ ASKED IN THE POSITIVE, the opposite of `teachesOnPlatform()` above, and
     * for the same reason: the safe direction wins. That predicate REFUSES
     * something, so an unknown role falls toward «teaches»; this one GRANTS, so
     * an unknown role falls toward «may not». The owner always holds a
     * `tenant-owner` row (`CreateWorkspace` writes it), so ownership needs no
     * branch of its own.
     *
     * ⚠️ AND AN ASSISTANT ASSIGNMENT WALLS IT whatever the role is named — the
     * wall is at the check, never on the role name (docs/gotchas/tenancy.md).
     */
    public function decidesCourseVisibilityIn(int $workspaceId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $teaches = $this->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->wherePivotIn('role', [Roles::TENANT_OWNER, Roles::TEACHER])
            ->exists();

        return $teaches && ! app(AssistantScopeDirectory::class)->isAssistantIn($this, $workspaceId);
    }

    /**
     * أيُّ أنواعِ البياناتِ الشخصيّةِ تخصُّ هذا الحساب.
     *
     * ⛔ شاشةُ «خصوصيّتي» كانت تعرضُ الثلاثةَ والثلاثينَ فئةً لكلِّ حساب، فقرأَ
     * مدرّسٌ عن «تقدّمك في الدروس» و«محاولاتك في الاختبارات» و«الأفكار التي
     * أتقنتها» — بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦. والجوابُ يُبنى هنا مرّةً، ويُقرَنُ
     * في الواجهةِ بـ`data_categories.subject_roles`.
     *
     * ⚠️ **مجموعةٌ لا قيمةٌ واحدة.** مدرّسٌ يدرسُ عندَ غيرِه، ووليُّ أمرٍ يدرسُ
     * هو أيضاً — كلاهما موجودٌ ويجبُ أن يرى الجانبَين. وقيمةٌ واحدةٌ تُجبِرُ على
     * اختيارِ أحدِهما وإخفاءِ الآخر.
     *
     * ⚠️ **والتدريسُ من الدَّورِ في المحور، لا من `platform_role`.** العمودُ
     * فارغٌ عندَ سبعةٍ وثلاثينَ حساباً منها ثمانيةٌ تحملُ دوراً تدريسيّاً —
     * قِيسَ على قاعدةٍ حقيقيّة — فقراءةٌ منه وحدَها تُخطئُ في ثمانيةٍ بلا أثر.
     *
     * ⚠️ **والمجموعةُ الفارغةُ تعني «اعرضِ الكلَّ» لا «لا تعرضْ شيئاً»، وهذا هو
     * القرار.** حسابٌ لا يُدرِّسُ ولا أعلنَ دورَه — وهو شكلٌ قائمٌ في القاعدة —
     * لا نعرفُ عنه شيئاً، وإخفاءُ فئةٍ بياناتُه فيها **شاشةُ موافقةٍ تكذِب**،
     * بينما عرضُ فئةٍ لا تخصُّه ضجيجٌ يُقرَأُ ويُتجاوَز. اتّجاهُ الخطأِ هو الذي
     * يحسِم.
     *
     * @return list<string> من مفرداتِ {@see PlatformRole} ولا ثالثةَ لها
     */
    public function dataSubjectRoles(): array
    {
        $roles = [];

        if ($this->teachesOnPlatform()) {
            $roles[] = PlatformRole::Teacher->value;
        }

        if ($this->platform_role === PlatformRole::Parent) {
            $roles[] = PlatformRole::Parent->value;
        }

        if ($this->platform_role === PlatformRole::Student) {
            $roles[] = PlatformRole::Student->value;
        }

        return $roles === []
            ? [PlatformRole::Student->value, PlatformRole::Teacher->value, PlatformRole::Parent->value]
            : $roles;
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

    /**
     * The row id of the token making the CURRENT request, or null when there is
     * no token row behind it.
     *
     * ⛔ **`currentAccessToken()` CAN RETURN A `TransientToken`, WHICH HAS
     * EXACTLY TWO METHODS: `can()` AND `cant()`.** Sanctum's `statefulApi()`
     * authenticates a same-domain request by the SESSION COOKIE when one is
     * present — every teacher here has one, because `/admin` is session-based —
     * and hands the user that marker instead of a model. So `?->getKey()` is NOT
     * safe: the null-safe operator guards a null, not a wrong class, and the
     * call is a fatal `Call to undefined method`.
     *
     * ⚠️ **AND THE VENDOR'S OWN GENERIC SAYS OTHERWISE.** `HasApiTokens` declares
     * `@return TToken`, so PHPStan reads whatever this class pins it to and
     * proves the `instanceof` below «always true» — the `AccessToken::$ttl`
     * family, where a vendor annotation disagreed with the code under it. The
     * generic is pinned to the union it really holds, which is what makes the
     * analyser check every OTHER call site instead of blessing them.
     *
     * Measured on production 2026-09-16: `POST /auth/logout` answered 500 with
     * `Call to undefined method Laravel\Sanctum\TransientToken::getKey()`, and
     * the account stayed signed in — the browser cleared its own storage and
     * moved to `/login`, so the person believed they had left a machine they had
     * not. Five call sites carried the same assumption.
     */
    public function currentTokenId(): ?int
    {
        $token = $this->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
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
