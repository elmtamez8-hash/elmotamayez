<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenancy's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class TenancyPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'tenancy';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['workspace_invitation'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        /*
        | ⚠️ `invitations.email` IS A BARE CONTACT DETAIL WITH NO ACCOUNT BEHIND IT,
        | which is what makes this module an owner at all. The row holds an address
        | for somebody who may never have signed up, so no `user_id` names them and
        | no cascade reaches them: an erasure elsewhere leaves the address sitting
        | here for ever, which is FR-020 and FR-023 broken by a table nobody
        | remembered. The exception once written for this module gave a reason that
        | was not true, and a wrong exception is worse than none.
        |
        | Matched by EMAIL as well as by `accepted_by`: before acceptance the email
        | is the only thing that names the person.
        */
        $email = $subject->user->email;

        // `token` is absent, and `ExportFieldAllowlist` fails the build over it at
        // any depth: it is a live credential that grants membership of a workspace.
        yield from ExportWalk::keyed(
            'workspace_invitation',
            Invitation::query()
                ->withoutWorkspaceScope()
                ->where(function (Builder $query) use ($subject, $email): void {
                    $query->where('accepted_by', $subject->user->getKey());

                    if ($email !== '') {
                        $query->orWhere('email', $email);
                    }
                }),
            fn (Invitation $invitation): array => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => ExportWalk::at($invitation->expires_at),
                'accepted_at' => ExportWalk::at($invitation->accepted_at),
                'invited_at' => ExportWalk::at($invitation->created_at),
            ],
        );

        // Where this person is a member, and since when. The workspace's NAME, not
        // its autoincrement id: an internal key means nothing to the person reading
        // this, and the repository exposes uuids and names in payloads, never ids.
        yield from ExportWalk::keyed(
            'workspace_invitation',
            WorkspaceMember::query()
                ->leftJoin('workspaces', 'workspaces.id', '=', 'workspace_members.workspace_id')
                ->where('workspace_members.user_id', $subject->user->getKey())
                ->select(['workspace_members.*', 'workspaces.name as workspace_name']),
            fn (WorkspaceMember $member): array => [
                'workspace_name' => $member->getAttribute('workspace_name'),
                'role' => $member->role,
                'joined_at' => ExportWalk::at($member->joined_at),
            ],
            column: 'workspace_members.id',
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Delete) {
            return 0;
        }

        /*
        | ⚠️ MATCHED BY EMAIL AS WELL AS BY `accepted_by`, AND THE EMAIL HALF IS THE
        | WHOLE REASON THIS MODULE IS AN OWNER. An invitation holds a bare address
        | for somebody who may never have signed up: no `user_id` names them, so no
        | cascade reaches them, and an erasure elsewhere leaves the address sitting
        | here for ever — FR-020 and FR-023 broken by a table nobody remembered.
        |
        | ⚠️ AND THIS RUNS BEFORE `Identity` ANONYMISES THE ACCOUNT, which is not an
        | accident of ordering but a requirement of it: once `users.email` becomes
        | `anonymised+…`, the address these rows hold is unreachable by any query.
        | `ExecuteDataErasure` partitions the walk to guarantee it, and the reason
        | is written there too.
        */
        $email = $subject->user->email;

        return Invitation::query()
            ->withoutWorkspaceScope()
            ->where(function (Builder $query) use ($subject, $email): void {
                $query->where('accepted_by', $subject->user->getKey());

                if ($email !== '') {
                    $query->orWhere('email', $email);
                }
            })
            ->limit($limit)
            ->delete();
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     *
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($category !== 'workspace_invitation' || $mode !== ExpiryBehaviour::Delete) {
            return 0;
        }

        /*
        | ⚠️ THIS IS THE ONLY CATEGORY IN THE CATALOGUE WHOSE ROWS NAME SOMEBODY WHO
        | MAY HAVE NO ACCOUNT AT ALL. An invitation holds a bare email address, so
        | no cascade and no erasure elsewhere ever reaches it — ninety days is what
        | keeps an address somebody never accepted from sitting here for ever.
        |
        | ⚠️ AND `accepted_by` IS EXEMPTED, NOT `email`. A held subject is a user id;
        | matching the held person's address as well would mean reading
        | `users.email` from inside this module for a handful of rows a court
        | order touches. An invitation that was ACCEPTED is the one that names a
        | real account, and that is the row a hold is about.
        */
        return Invitation::query()
            ->withoutWorkspaceScope()
            ->where('created_at', '<', $before->toDateTimeString())
            /*
            | ⚠️ GROUPED, AND UNGROUPED IT DELETES THE WHOLE TABLE. A bare
            | `orWhereNull()` beside the exemption ORs at the TOP level, which
            | discards the `created_at` bound above it — every pending invitation
            | on the platform, gone on the first night a hold exists. The
            | `orWhereNull` itself is required: `NULL NOT IN (…)` evaluates to
            | NULL, so a plain `whereNotIn` silently spares every unaccepted row.
            */
            ->when($exemptUserIds !== [], fn ($query) => $query->where(
                fn ($inner) => $inner->whereNull('accepted_by')->orWhereNotIn('accepted_by', $exemptUserIds),
            ))
            ->limit($limit)
            ->delete();
    }
}
