<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Shared\Support\WorkspaceContext;

beforeEach(function () {
    $this->workspace = marketplaceWorkspace('Academy');
});

it('returns the full profile for a published teacher', function () {
    $teacher = marketplaceTeacher($this->workspace);

    $this->asGuest();

    $response = $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}");

    $response->assertOk()->assertJsonPath('data.uuid', $teacher->uuid);

    $data = $response->json('data');

    expect($data)->toHaveKeys([
        'bio', 'qualifications', 'stats', 'trust_score_factors',
        'courses', 'reviews', 'availability', 'faqs',
    ]);
    expect($data['stats'])->toHaveKeys([
        'students_taught', 'completed_sessions', 'response_rate', 'attendance_rate',
    ]);
});

it('exposes the five trust factors behind the score', function () {
    $teacher = marketplaceTeacher($this->workspace);

    $this->asGuest();

    $factors = $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")
        ->json('data.trust_score_factors');

    expect($factors)->toHaveKeys([
        'student_rating', 'punctuality', 'completion', 'tenure', 'complaints_penalty',
    ]);
});

it('returns availability in UTC so the client can localise it', function () {
    $teacher = marketplaceTeacher($this->workspace);

    app(WorkspaceContext::class)->forWorkspace($this->workspace, fn () => AvailabilitySlot::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'day_of_week' => 1,
        'start_time' => '13:00:00',
        'end_time' => '15:00:00',
    ]));

    $this->asGuest();

    $availability = $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")
        ->json('data.availability');

    expect($availability)->toBe([
        ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '15:00'],
    ]);
});

it('reports no reviews yet rather than a zero average', function () {
    $teacher = marketplaceTeacher($this->workspace, ['average_rating' => null, 'reviews_count' => 0]);

    $this->asGuest();

    $reviews = $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")->json('data.reviews');

    expect($reviews['average'])->toBeNull();
    expect($reviews['total'])->toBe(0);
});

it('returns the same 404 for an unknown uuid as for a hidden teacher', function () {
    $hidden = marketplaceTeacher(marketplaceWorkspace('Private Academy', participates: false));

    $this->asGuest();

    $unknown = $this->getJson('/api/v1/marketplace/teachers/'.fake()->uuid());
    $withheld = $this->getJson("/api/v1/marketplace/teachers/{$hidden->uuid}");

    // Identical responses: a different status or message would confirm that an
    // unpublished profile exists behind that uuid.
    $unknown->assertNotFound();
    $withheld->assertNotFound();
    expect($withheld->json('message'))->toBe($unknown->json('message'));
});
