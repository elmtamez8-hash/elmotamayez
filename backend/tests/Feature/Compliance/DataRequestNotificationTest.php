<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Actions\ExecuteDataErasure;
use App\Modules\Compliance\Actions\ExecuteDataExport;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * The receipt and the completion of a data-rights request (spec 013 · US4 §1 ·
 * FR-026).
 *
 * ⚠️ BOTH TYPES SHIPPED WITH A LABEL, A TEMPLATE AND A ROW IN THE SPEC'S
 * NOTIFICATION TABLE — AND NOTHING SENT EITHER ONE. A student who asked for a
 * copy of their data got no acknowledgement and no word that the archive was
 * ready; the download link lived on a screen they had to think to reopen,
 * inside an expiry window. `EveryNotificationTypeIsTestedTest` found them by
 * looking for a producer rather than a template.
 */
/** @return Collection<int, Notification> */
function dataRequestRowsOfType(NotificationType $type): Collection
{
    return Notification::query()->where('type', $type->value)->get();
}

beforeEach(function (): void {
    Storage::fake('local');

    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);
});

it('acknowledges an export request and announces its completion to the student who asked', function (): void {
    Sanctum::actingAs($this->student);

    // The real door: the controller creates the request and, for an export,
    // queues the job — which runs inline on `sync`.
    $this->postJson('/api/v1/privacy/requests', ['type' => DataRequestType::Export->value])
        ->assertCreated();

    $request = DataRequest::query()->sole();
    expect($request->status)->toBe(DataRequestStatus::Completed);

    $created = dataRequestRowsOfType(NotificationType::DataRequestCreated);
    $completed = dataRequestRowsOfType(NotificationType::DataRequestCompleted);

    expect($created)->toHaveCount(1)
        ->and((int) $created->first()->recipient_user_id)->toBe((int) $this->student->getKey())
        ->and($completed)->toHaveCount(1)
        ->and((int) $completed->first()->recipient_user_id)->toBe((int) $this->student->getKey());

    foreach ([$created->first(), $completed->first()] as $row) {
        expect((string) $row->title)->not->toBe('')
            ->and((string) $row->body)->not->toBe('')
            ->and((string) $row->body)->not->toContain('{{');
    }

    // The receipt names the deadline the officer is held to.
    expect((string) $created->first()->body)->toContain($request->due_at->format('Y-m-d'));
});

it('sends one receipt for a request opened twice', function (): void {
    $action = app(CreateDataRequest::class);

    $first = $action->handle($this->student, (string) $this->student->uuid, DataRequestType::Export);
    $second = $action->handle($this->student, (string) $this->student->uuid, DataRequestType::Export);

    // The second tap reads the request already open (`open_key`) — and a second
    // receipt would announce as new something received a moment ago.
    expect($second->getKey())->toBe($first->getKey())
        ->and(dataRequestRowsOfType(NotificationType::DataRequestCreated))->toHaveCount(1);
});

it('tells the guardian who asked, not the child, and says nothing of a download after an erasure', function (): void {
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $this->student->getKey(),
        'student_name' => 'طالب',
        'relation_type' => RelationType::Parent->value,
        'permissions' => [GuardianPermission::DataRights->value],
        'status' => RelationStatus::Active->value,
    ]);

    $request = app(CreateDataRequest::class)->handle($guardian, (string) $this->student->uuid, DataRequestType::Erasure);

    $created = dataRequestRowsOfType(NotificationType::DataRequestCreated);

    expect($created)->toHaveCount(1)
        ->and((int) $created->first()->recipient_user_id)->toBe((int) $guardian->getKey());

    // An erasure produces nothing to download, and the completion template says
    // to go and download the file.
    (new FulfilDataRequestJob((int) $request->getKey()))->handle(
        app(ExecuteDataExport::class),
        app(ExecuteDataErasure::class),
    );

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and(dataRequestRowsOfType(NotificationType::DataRequestCompleted))->toHaveCount(0);
});
