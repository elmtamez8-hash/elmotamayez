<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| FR-019 — PINNING WHAT ALREADY SHIPPED. Nothing here is new work; the point is
| that the guarantee stops being true silently if anyone swaps the signed route
| for a stored path, and a receipt is the payer's bank document.
|
| Three claims, three different failures:
|   · the link is signed and expires — a permanent one is a financial document
|     on a shareable URL;
|   · an expired link is refused, which is what "قصير المدة" has to mean;
|   · the file is never on the public disk, so there is no path around the link.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $this->postJson("/api/v1/orders/{$this->order->uuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('transfer.png'),
    ])->assertOk();
});

it('serves the receipt through a signed link that expires', function (): void {
    // Not wrapped in `data`: `show` json-encodes the Resource itself, and only
    // the paginated `index` carries the collection envelope.
    $url = $this->getJson("/api/v1/orders/{$this->order->uuid}")
        ->assertOk()
        ->json('receipt_url');

    expect($url)->toBeString()
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('expires=');

    $this->get($url)->assertOk();
});

it('refuses the same link once its fifteen minutes are up', function (): void {
    $url = $this->getJson("/api/v1/orders/{$this->order->uuid}")
        ->assertOk()
        ->json('receipt_url');

    // Sixteen, not fifteen: at exactly the boundary the comparison is inclusive
    // and the assertion would be about the clock rather than about the rule.
    CarbonImmutable::setTestNow(now()->addMinutes(16));

    $this->get($url)->assertForbidden();

    CarbonImmutable::setTestNow();
});

it('keeps the file off every public path', function (): void {
    // The collection is on the `local` disk, which has no `url` in
    // config/filesystems.php — so there is no address to walk to, signed or not.
    expect($this->order->getFirstMedia('receipt')?->disk)->toBe('local');

    Storage::disk('public')->assertDirectoryEmpty('/');
});
