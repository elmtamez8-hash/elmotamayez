<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Enums\BreachStatus;
use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * SC-020 — a published reporting path that tells nobody anything.
 *
 * ⚠️ THE CENTRAL ASSERTION IS THAT TWO REPORTS ANSWER IDENTICALLY. An
 * unauthenticated route that varied its reply by what it found — an address that
 * holds an account, a report that already exists — would be an oracle worth
 * querying, and every one of those answers is more valuable to an attacker than
 * the report is to us. Comparing the two responses byte for byte is the only form
 * of that requirement a test can hold: asserting "returns 202" passes just as well
 * against a body carrying the reporter's own user id.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$workspace] = $this->createWorkspaceWithOwner();
    $this->student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
});

it('accepts a report from somebody who holds no account', function (): void {
    $response = $this->postJson('/api/v1/privacy/breach-reports', [
        'description' => 'رأيتُ روابطَ تسجيلاتٍ تُفتح بلا تسجيلِ دخولٍ من محرّك بحث.',
        'reporter_contact' => 'researcher@example.org',
    ]);

    $response->assertStatus(202);

    $report = BreachReport::query()->sole();

    expect($report->reported_by_user_id)->toBeNull()
        ->and($report->status)->toBe(BreachStatus::Reported)
        ->and($report->reporter_contact)->toBe('researcher@example.org');
});

it('answers a reporter whose contact holds an account exactly as it answers a stranger', function (): void {
    $member = User::query()->where('email', $this->student->email)->sole();

    $known = $this->postJson('/api/v1/privacy/breach-reports', [
        'description' => 'وصفٌ كافٍ لحادثٍ مزعومٍ رقم واحد.',
        'reporter_contact' => $member->email,
    ]);

    $stranger = $this->postJson('/api/v1/privacy/breach-reports', [
        'description' => 'وصفٌ كافٍ لحادثٍ مزعومٍ رقم اثنين.',
        'reporter_contact' => 'nobody-at-all@example.org',
    ]);

    expect($known->status())->toBe($stranger->status())
        ->and($known->getContent())->toBe($stranger->getContent());
});

it('returns nothing that names a record or a table', function (): void {
    $body = $this->postJson('/api/v1/privacy/breach-reports', [
        'description' => 'وصفٌ كافٍ لحادثٍ مزعومٍ يُختبَر به جسمُ الردّ.',
    ])->assertStatus(202)->json();

    /*
    | The uuid, the id and the count are each an oracle of their own: a uuid handed
    | back is a handle to a record the reporter has no right to read, and a running
    | count answers "how many incidents does this platform have open" to anybody.
    */
    expect(array_keys($body))->toBe(['message']);

    $report = BreachReport::query()->sole();

    expect($body['message'])->not->toContain((string) $report->uuid)
        ->and($body['message'])->not->toContain('breach_report');
});

it('records a signed-in reporter without changing the answer', function (): void {
    $anonymous = $this->postJson('/api/v1/privacy/breach-reports', [
        'description' => 'بلاغٌ من زائرٍ لا يملك حساباً على المنصّة.',
    ]);

    Sanctum::actingAs($this->student);

    $identified = $this->postJson('/api/v1/privacy/breach-reports', [
        'description' => 'بلاغٌ من حسابٍ مسجَّلٍ داخلَ المنصّة.',
    ]);

    expect($identified->getContent())->toBe($anonymous->getContent());

    $reports = BreachReport::query()->orderBy('id')->get();

    expect($reports->first()?->reported_by_user_id)->toBeNull()
        ->and($reports->last()?->reported_by_user_id)->toBe((int) $this->student->getKey());
});

it('refuses the scope of an incident from the reporter', function (): void {
    $this->postJson('/api/v1/privacy/breach-reports', [
        'description' => 'بلاغٌ يحاول أن يقرّر بنفسه حجمَ الحادثِ وحالتَه.',
        'affected_subject_count' => 1,
        'affected_categories' => ['identity'],
        'status' => BreachStatus::Closed->value,
    ])->assertStatus(202);

    $report = BreachReport::query()->sole();

    /*
    | Anybody may report; nobody outside may size the incident or declare it shut.
    | Both fields are absent from the request AND outside `$fillable`, which is two
    | guards rather than one because mass assignment discards a non-fillable key in
    | silence — a rule that has already cost this spec three columns.
    */
    expect($report->affected_subject_count)->toBeNull()
        ->and($report->affected_categories)->toBeNull()
        ->and($report->status)->toBe(BreachStatus::Reported);
});

it('refuses a description too short to act on', function (): void {
    $this->postJson('/api/v1/privacy/breach-reports', ['description' => 'تسريب'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');

    expect(BreachReport::query()->count())->toBe(0);
});

it('keeps the queue behind the breach permission', function (): void {
    Sanctum::actingAs($this->student);

    $this->getJson('/api/v1/manage/compliance/breach-reports')->assertForbidden();

    Sanctum::actingAs($this->officer);

    $this->getJson('/api/v1/manage/compliance/breach-reports')->assertOk();
});

it('shows the officer a deadline derived from the hour the report arrived', function (): void {
    $report = BreachReport::query()->create(['description' => 'حادثٌ للفحص.']);
    $report->forceFill(['created_at' => now()->subHours(2)])->save();

    Sanctum::actingAs($this->officer);

    $row = $this->getJson('/api/v1/manage/compliance/breach-reports')->assertOk()->json('0');

    /*
    | ⚠️ MEASURED AGAINST THE ACCESSOR, NEVER AGAINST `72`. The notice window is a
    | `platform_settings` row an operator tunes — the number a regulator shortens is
    | exactly the number that changes — and a literal here fails the gate over a
    | legitimate config change while proving nothing extra. Same lesson the LiveKit
    | ticket ttl already cost this repository once.
    */
    expect($row['authority_notice_due_at'])->toBe(
        $report->fresh()?->created_at?->addHours(ComplianceSettings::authorityNoticeHours())->toIso8601String(),
    );
});

it('refuses to walk a report backwards', function (): void {
    $report = BreachReport::query()->create(['description' => 'حادثٌ احتُوي بالفعل.']);
    $report->forceFill(['status' => BreachStatus::Contained])->save();

    Sanctum::actingAs($this->officer);

    $this->patchJson("/api/v1/manage/compliance/breach-reports/{$report->uuid}", [
        'status' => BreachStatus::Triaged->value,
    ])->assertStatus(422);

    expect($report->fresh()?->status)->toBe(BreachStatus::Contained);
});

it('refuses notified until both the authority and the affected people are recorded', function (): void {
    $report = BreachReport::query()->create(['description' => 'حادثٌ لم يُبلَّغ عنه بعد.']);

    Sanctum::actingAs($this->officer);
    $url = "/api/v1/manage/compliance/breach-reports/{$report->uuid}";

    // The authority alone is not enough: the two obligations run on two clocks,
    // which is why the table carries two columns and not a flag.
    $this->patchJson($url, [
        'status' => BreachStatus::Notified->value,
        'authority_notified' => true,
    ])->assertStatus(422);

    /*
    | AND THE REFUSED CALL WRITES NOTHING AT ALL — not the status, and not the
    | notification it carried alongside it. The refusal is raised before the row is
    | saved, so a rejected PATCH leaves the record exactly as the officer found it
    | rather than half-applied, which is the state nothing would ever reconcile.
    */
    expect($report->fresh()?->status)->toBe(BreachStatus::Reported)
        ->and($report->fresh()?->authority_notified_at)->toBeNull();

    $this->patchJson($url, [
        'status' => BreachStatus::Triaged->value,
        'authority_notified' => true,
    ])->assertOk();

    $this->patchJson($url, [
        'status' => BreachStatus::Notified->value,
        'subjects_notified' => true,
    ])->assertOk();

    expect($report->fresh()?->status)->toBe(BreachStatus::Notified);
});

it('never re-stamps a notification that already happened', function (): void {
    $report = BreachReport::query()->create(['description' => 'حادثٌ أُبلغ عنه أمس.']);

    Sanctum::actingAs($this->officer);
    $url = "/api/v1/manage/compliance/breach-reports/{$report->uuid}";

    $this->patchJson($url, [
        'status' => BreachStatus::Triaged->value,
        'authority_notified' => true,
    ])->assertOk();

    $stamped = $report->fresh()?->authority_notified_at;

    $this->travel(3)->hours();

    $this->patchJson($url, [
        'status' => BreachStatus::Contained->value,
        'authority_notified' => true,
        'affected_subject_count' => 12,
    ])->assertOk();

    /*
    | The timestamp is the record of WHEN a legal obligation was discharged, and it
    | is measured against a deadline. Re-stamping it on a later edit moves that fact
    | to whenever somebody last touched the row — always inside the window, however
    | late the notification actually was.
    */
    expect($report->fresh()?->authority_notified_at?->toIso8601String())
        ->toBe($stamped?->toIso8601String())
        ->and($report->fresh()?->affected_subject_count)->toBe(12);
});
