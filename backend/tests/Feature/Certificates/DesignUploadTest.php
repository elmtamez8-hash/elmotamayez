<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** A real JPEG on disk, with an ASCII marker appended after its end-of-image. */
function designFileCarrying(string $sentinel): string
{
    $path = sys_get_temp_dir().'/design-'.uniqid().'.jpg';
    $image = imagecreatetruecolor(900, 640);

    imagefill($image, 0, 0, imagecolorallocate($image, 240, 230, 210));
    imagejpeg($image, $path, 90);
    imagedestroy($image);

    // Decoders stop at the EOI marker, so this rides along invisibly — which is
    // exactly what makes a polyglot file pass `mimes:`.
    file_put_contents($path, $sentinel, FILE_APPEND);

    return $path;
}

describe('uploading a certificate design', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');

        [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Academy']);

        $this->workspace = $workspace;
        $this->teacher = $owner;

        Sanctum::actingAs($owner);
        $this->setCurrentWorkspace($workspace, $owner);
    });

    it('refuses a format it cannot re-encode, and says which it takes', function (): void {
        $response = $this->postJson('/api/v1/certificate-designs', [
            'image' => UploadedFile::fake()->create('design.pdf', 200, 'application/pdf'),
        ])->assertStatus(422);

        // ⚠️ `FR-043`: a refusal that does not name the accepted formats sends a
        // teacher back to try the same file again.
        expect($response->json('message'))->toContain('webp');
    });

    it('refuses a file over the ceiling, and says what the ceiling is', function (): void {
        $response = $this->postJson('/api/v1/certificate-designs', [
            'image' => UploadedFile::fake()->image('design.jpg', 900, 640)->size(9000),
        ])->assertStatus(422);

        expect($response->json('message'))->toContain('ميغابايت');
    });

    it('refuses both a template key and a file in one request', function (): void {
        $this->postJson('/api/v1/certificate-designs', [
            'system_key' => 'classic',
            'image' => UploadedFile::fake()->image('design.jpg', 900, 640),
        ])->assertStatus(422);
    });

    it('refuses a request naming neither', function (): void {
        $this->postJson('/api/v1/certificate-designs', [])->assertStatus(422);
    });

    it('refuses an upload past the announced limit', function (): void {
        PlatformSettings::set('certificates.design_upload_limit', 1);

        $this->postJson('/api/v1/certificate-designs', [
            'image' => UploadedFile::fake()->image('one.jpg', 900, 640),
            'name' => 'الأوّل',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/certificate-designs', [
            'image' => UploadedFile::fake()->image('two.jpg', 900, 640),
            'name' => 'الثاني',
        ])->assertStatus(422);

        expect($response->json('message'))->toContain('احذف');
    });

    it('arrives unready and unselected, and cannot be adopted until it is adjusted', function (): void {
        $uuid = $this->postJson('/api/v1/certificate-designs', [
            'image' => UploadedFile::fake()->image('mine.jpg', 900, 640),
            'name' => 'تصميم المركز',
        ])
            ->assertCreated()
            ->assertJsonPath('is_ready', false)
            ->assertJsonPath('is_selected', false)
            ->assertJsonPath('boxes', null)
            ->json('uuid');

        // ⚠️ SC-013: an image with no field positions prints the student's name
        // over the artwork's ornament, and the student is the first to see it.
        $this->patchJson("/api/v1/certificate-designs/{$uuid}", ['is_selected' => true])
            ->assertStatus(422);
    });

    it('re-encodes the file that is actually stored, not the response', function (): void {
        // ⚠️ MEASURED ON THE STORED BYTES. The response echoes what was submitted,
        // so asserting on it passes against an implementation that stores the file
        // untouched — the `student_profiles` lesson, where every US1 assertion was
        // correct and none of them could see three columns silently discarded.
        $sentinel = 'MTEATCH-POLYGLOT-PAYLOAD';
        $path = designFileCarrying($sentinel);

        $this->postJson('/api/v1/certificate-designs', [
            'image' => new UploadedFile($path, 'design.jpg', 'image/jpeg', null, true),
            'name' => 'مُعاد ترميزه',
        ])->assertCreated();

        $design = CertificateDesign::withoutWorkspaceScope()->whereNull('system_key')->firstOrFail();

        expect($design->image_path)->toEndWith('.webp');
        expect(Storage::disk('public')->exists($design->image_path))->toBeTrue();
        expect(Storage::disk('public')->get($design->image_path))->not->toContain($sentinel);
    });

    it('deletes the row and its file, and falls back to the shipped default', function (): void {
        $uuid = $this->postJson('/api/v1/certificate-designs', [
            'image' => UploadedFile::fake()->image('mine.jpg', 900, 640),
        ])->assertCreated()->json('uuid');

        $path = CertificateDesign::withoutWorkspaceScope()->whereNull('system_key')->firstOrFail()->image_path;

        $this->deleteJson("/api/v1/certificate-designs/{$uuid}")->assertNoContent();

        expect(CertificateDesign::withoutWorkspaceScope()->count())->toBe(0);
        // An image nothing points at is storage nobody ever reads.
        expect(Storage::disk('public')->exists($path))->toBeFalse();
    });

    it('never shows one teacher another teacher design', function (): void {
        $this->postJson('/api/v1/certificate-designs', [
            'image' => UploadedFile::fake()->image('mine.jpg', 900, 640),
            'name' => 'تصميم المركز',
        ])->assertCreated();

        [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'Other']);

        Sanctum::actingAs($otherOwner);
        $this->setCurrentWorkspace($otherWorkspace, $otherOwner);

        $response = $this->getJson('/api/v1/certificate-designs')->assertOk();

        // Only the two shipped templates — `FR-041`.
        expect($response->json('data'))->toHaveCount(2);
        expect($response->json('uploads_used'))->toBe(0);
        expect(collect($response->json('data'))->pluck('name')->all())->not->toContain('تصميم المركز');
    });
});
