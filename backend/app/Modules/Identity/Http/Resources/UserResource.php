<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Identity\Actions\SaveAccountPhoto;
use App\Modules\Marketplace\Support\SchoolYearDirectory;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'status' => $this->status,
            'is_super_admin' => $this->is_super_admin,
            // FR-012: the frontend routes on this after login. Hidden on the model
            // so it never leaks through a stray ->toArray(); named here on purpose.
            'platform_role' => $this->platform_role?->value,
            'last_workspace_id' => $this->last_workspace_id,
            // Nested rather than flattened onto the root: these are true of a
            // student and of nobody else, and a null grade_level_slug on a
            // teacher's payload reads as missing data rather than as inapplicable.
            //
            // Read straight off the relation, not whenLoaded: every caller of this
            // resource passes a single user (never a collection), so the lazy load
            // is one query, and whenLoaded would silently omit the key wherever a
            // caller forgot to eager-load it.
            'student_profile' => $this->studentProfile === null ? null : [
                /*
                | ⚠️ `grade_level_slug` KEEPS ITS NAME AND BECOMES DERIVED
                | (spec 022 · FR-005). Every existing reader asks for a broad
                | stage and must keep getting one; what changed is where the
                | answer comes from — the student's YEAR, falling back to the old
                | column for anyone who registered before years existed. Renaming
                | the key would break readers for no gain; adding a second stage
                | key would be two answers to one question.
                */
                'grade_level_slug' => $this->studentProfile->stageSlug(),
                'school_year_slug' => $this->studentProfile->school_year_slug,
                'school_year_name' => app(SchoolYearDirectory::class)
                    ->nameFor($this->studentProfile->school_year_slug),
                'registered_by_parent' => $this->studentProfile->registered_by_parent,
                // The region was stored at registration and never sent back, so
                // an edit form had nothing to prefill itself with — and the
                // student would have re-picked it blind on every save.
                'region_slug' => $this->studentProfile->region?->slug,
            ],
            /*
            | صورةُ الحساب، مهما كانَ الملفُّ الذي تحملُها.
            |
            | ⚠️ هنا في الجذرِ لا داخلَ `student_profile`: «ما صورةُ هذا الحساب؟»
            | سؤالٌ صحيحٌ عن كلِّ حساب، والعمودُ الذي يجيبُه يختلفُ باختلافِ
            | الملفِّ لا باختلافِ السؤال. ومفتاحانِ — واحدٌ للمدرّسِ وآخرُ للطالبِ —
            | يعنيانِ أنّ كلَّ شاشةٍ ترسمُ صورةً تسألُ سؤالَينِ وتنسى أحدَهما.
            */
            'photo_url' => $this->accountPhotoUrl(),
            /*
            | ⚠️ «هل يستضيفُ هذا الحسابُ حصصاً؟» — لا «ما دَورُه؟» (٠٢٩ · `FR-007أ`).
            |
            | اللوحةُ تقيِّدُ به قراءةَ الحصصِ على صاحبِها (`?teacher={uuid}`
            | تحتَ «حصصي»)، وتُفرِّقُ به بينَ **مدرّسٍ بلا حصصٍ هذا الأسبوع**
            | و**مساعدٍ لا يستضيفُ شيئاً أصلاً**: جملتانِ مختلفتانِ على الشاشة،
            | وبلا الحقلِ تُرسَمُ الأولى مكانَ الثانية.
            |
            | ⚠️ ولا تحميلَ مسبقٌ يُضاف: `accountPhotoUrl()` أسفلَه يقرأُ
            | `teacherProfile` على كلِّ نداءٍ سلفاً، فالعلاقةُ محمَّلةٌ قبلَ أن
            | يصلَ هذا السطرُ وكلفتُه صفرُ استعلامات — و`whenLoaded` هنا كانت
            | ستُسقِطُ المفتاحَ عندَ كلِّ مُنادٍ لم يُحمِّل، وهو السببُ نفسُه
            | المكتوبُ فوقَ `student_profile`.
            */
            'teacher_profile_uuid' => $this->teacherProfile?->uuid,
            /*
             | ⚠️ WHAT THIS PERSON MAY DO, because the client had no way to ask.
             |
             | The panel's sidebar offered every teacher screen to every account:
             | a student signed in and was shown the course editor, the exam
             | builder, the question bank and the settlement statement. The server
             | refused all four with a 403 — this was never an authorisation hole —
             | but a menu of links that answer "forbidden" reads as a product that
             | is broken, and it teaches the reader that the app does not know who
             | they are.
             */
            'permissions' => $this->grantedPermissions(),
            /*
             | ⚠️ `workspaces`, NOT `workplaces` — spec 025 · FR-021 draws the line
             | itself: what changes is what a PERSON reads, not what a machine
             | does. Two keys one letter apart in the payload that also carries
             | `last_workspace_id` is the mistake made once per reading. The
             | Arabic renaming lives in the interface text, where FR-012 lives.
             |
             | Purely additive, so no existing reader breaks. `uuid` and never
             | `id` (Constitution VI); `last_workspace_id` stays despite being a
             | serial, because it is a field today's clients read and dropping it
             | would break a contract for nothing.
             |
             | ⚠️ SKIPPED ENTIRELY FOR A STUDENT OR A GUARDIAN, who are the
             | majority of accounts and are members of nothing by design — the
             | premise `WorkspaceScope` rests on. Zero rows, always, so the query
             | is pure cost. The empty list is still SENT: absent and empty are
             | different answers, and the banner reads the count.
             */
            'workspaces' => $this->workplaces(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The places this person works — owned or assisted at (spec 025 · FR-014).
     *
     * ⚠️ THE COUNT IS WHAT THE INTERFACE READS, and all three answers differ:
     * zero means no banner at all, one means the PLACE'S OWN NAME (never a
     * singular carved out of a plural, which keeps telling the reader there are
     * others), and more means «أماكن عملي». So this ships the name, not just a
     * number, and the banner needs no second fetch inside the shell layout.
     *
     * ⚠️ IT RIDES ON EIGHT ENDPOINTS, not just `/auth/me` — `UserResource` is
     * returned by register, registerStudent, login, me, updateProfile, the parent
     * controller and the two-factor controller. Hence the student/parent skip:
     * one query saved on every one of them, for the accounts that are most of the
     * platform.
     *
     * @return list<array{uuid: string, name: string}>
     */
    private function workplaces(): array
    {
        $role = $this->platform_role?->value;

        if ($role === 'student' || $role === 'parent') {
            return [];
        }

        return $this->resource->workspaces()
            ->get(['workspaces.uuid', 'workspaces.name'])
            ->map(fn ($workspace): array => [
                'uuid' => (string) $workspace->uuid,
                'name' => (string) $workspace->name,
            ])
            ->all();
    }

    /**
     * Every permission name this user holds in the workspace they are in.
     *
     * ⚠️ ASKED THROUGH THE GATE, never through spatie's `getAllPermissions()`.
     * A super admin's powers come from `users.is_super_admin` and a platform
     * officer's from `platform_staff`, and BOTH are granted by a `Gate::before`
     * hook that spatie's own accessors cannot see — so the spatie-derived list
     * hands the super admin an empty sidebar. Both layers memoise per request, so
     * walking the constants costs nothing worth caching.
     *
     * ⚠️ AND IT IS COMPUTED INSIDE `forWorkspace()`, which is what makes the
     * LOGIN response correct. spatie runs in team mode, and during `/auth/login`
     * the context resolved as a guest and froze there — the singleton caches its
     * resolution — so every check would answer false and the panel would come up
     * with an empty menu until the first reload.
     *
     * @return list<string>
     */
    private function grantedPermissions(): array
    {
        $user = $this->resource;

        $resolve = static function () use ($user): array {
            /*
            | ⚠️ THE LOADED RELATIONS ARE DROPPED FIRST, and without this line the
            | answer is whichever workspace was asked about EARLIER in the same
            | process. spatie reads `$user->roles`, and once Eloquent has loaded
            | that relation it hands back the same rows however the team id moves
            | underneath it. Two symptoms, both found by the tests beside this
            | file: a person who teaches at one academy and studies at another saw
            | the first one's menu at the second, and the LOGIN response — where
            | the guest-resolved context is replaced a moment later — came back
            | with an empty list.
            */
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $granted = [];

            foreach (Permissions::all() as $permission) {
                if ($user->can($permission)) {
                    $granted[] = $permission;
                }
            }

            return $granted;
        };

        $workspaceId = app(WorkspaceContext::class)->id() ?? $user->last_workspace_id;

        // No workspace at all is a marketplace account, and the honest answer for
        // one is the empty list — not a guess at what they would hold somewhere.
        return $workspaceId === null
            ? $resolve()
            : app(WorkspaceContext::class)->forWorkspace((int) $workspaceId, $resolve);
    }

    /**
     * المسارُ العامُّ لصورةِ الحساب.
     *
     * ملفُّ المدرّسِ أوّلاً ثمّ ملفُّ الطالب، بنفسِ ترتيبِ
     * {@see SaveAccountPhoto} — ترتيبانِ
     * مختلفانِ يعنيانِ حساباً يرفعُ صورةً في مكانٍ وتُقرأُ من مكانٍ آخر.
     */
    private function accountPhotoUrl(): ?string
    {
        $path = $this->teacherProfile === null
            ? $this->studentProfile?->avatar_path
            : $this->teacherProfile->photo_path;

        return $path === null ? null : asset('storage/'.$path);
    }
}
