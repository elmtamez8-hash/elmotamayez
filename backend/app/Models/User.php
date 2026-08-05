<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Cast in casts() below; restated here because Larastan reads the column type
 * from the migration, where platform_role is a plain string.
 *
 * @property PlatformRole|null $platform_role
 * @property string|null $phone
 * @property string|null $country
 * @property string|null $grade_level_slug
 * @property bool $registered_by_parent
 */
class User extends Authenticatable implements MustVerifyEmail
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
            'registered_by_parent' => 'boolean',
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
}
