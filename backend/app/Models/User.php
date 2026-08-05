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
use App\Shared\Traits\HasUuid;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

/**
 * Cast in casts() below; restated here because Larastan reads the column type
 * from the migration, where platform_role is a plain string.
 *
 * @property PlatformRole|null $platform_role
 * @property string|null $phone
 * @property string|null $country
 */
class User extends Authenticatable implements HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable;

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
