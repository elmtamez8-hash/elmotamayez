<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\BillingAuditSubjects;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Support\SettlementAuditSubjects;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Models\ActivityEntry;

/*
| FR-034 — BOTH DIRECTIONS, because one direction is not a separation.
|
| `activity_log` is a single shared table. An order approval, a teacher's payout,
| a certificate and a workspace rename all land in it. The two audit endpoints
| each name their own subject types and neither ever asks for the table — so:
|
|   · the billing filter must not return a settlement subject, and
|   · the settlement filter must not return a billing one.
|
| Testing only the first would leave the disclosure that actually matters — a
| student's payment shown to an auditor of teacher pay — resting on nothing but
| the order the two lists happened to be written in.
|
| ⚠️ AND THE TWO LISTS MUST NOT INTERSECT AT ALL, which is asserted directly:
| a model added to both would satisfy every per-direction test above while
| joining the two contexts through the audit.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $this->actingAs($this->owner);
    app(ApproveOrder::class)->handle($order, $this->owner, '198.51.100.4', 'Firefox');

    // A settlement decision in the same table, written the way 014 writes one.
    $rate = SettlementRate::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    activity()->performedOn($rate)->causedBy($this->owner)->log('rate.approved');
});

it('never shows a settlement subject to the billing audit', function (): void {
    $descriptions = ActivityEntry::query()
        ->whereIn('subject_type', BillingAuditSubjects::types())
        ->pluck('description')
        ->all();

    expect($descriptions)->toContain('approved')
        ->and($descriptions)->not->toContain('rate.approved');
});

it('never shows a billing subject to the settlement audit', function (): void {
    $descriptions = ActivityEntry::query()
        ->whereIn('subject_type', SettlementAuditSubjects::types())
        ->pluck('description')
        ->all();

    expect($descriptions)->toContain('rate.approved')
        // The direction that costs the most if it breaks: what a student paid,
        // read by someone auditing what a teacher was paid.
        ->and($descriptions)->not->toContain('approved');
});

it('shares not one subject type between the two contexts', function (): void {
    $shared = array_intersect(BillingAuditSubjects::types(), SettlementAuditSubjects::types());

    // A model in both lists would pass every test above and still join the two
    // contexts — each filter would be correct about itself and wrong together.
    expect($shared)->toBe([]);
});

it('leaves an entry belonging to neither context out of both', function (): void {
    // A workspace rename, a certificate, a lesson — the table's other writers.
    // The filters name what they want; anything unnamed is invisible to both,
    // which is what "the filter is the query" has to mean.
    activity()->performedOn($this->student)->log('user.renamed');

    $billing = ActivityEntry::query()->whereIn('subject_type', BillingAuditSubjects::types())->count();
    $settlement = ActivityEntry::query()->whereIn('subject_type', SettlementAuditSubjects::types())->count();

    expect(ActivityEntry::query()->count())->toBeGreaterThan($billing + $settlement);
});
