<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            // Polymorphic from the start: a Lesson today, a ClassSession in the
            // next phase. Two nullable foreign keys with a check constraint would
            // be the same thing spelled worse.
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            // Never exposed in a payload (FR-011).
            $table->string('provider', 32);
            $table->string('provider_asset_id', 191)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('original_filename');
            // Settled from the file's own bytes, never from what the client claimed.
            $table->string('mime_type', 96)->nullable();
            // unsignedBigInteger, not unsignedInteger: a 5 GB upload overflows the
            // smaller column, and SQLite would accept it locally while MySQL in
            // strict mode rejects it in production.
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('renditions')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'owner_type', 'owner_id']);
            // Keeps the reconciliation job off a full scan.
            $table->index(['status', 'updated_at']);
        });

        Schema::create('media_captions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('media_asset_id');
            $table->string('language', 8)->default('ar');
            $table->string('kind', 16)->default('captions');
            $table->string('source', 16)->default('manual');
            $table->string('storage_path');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['media_asset_id', 'language', 'kind']);
        });

        Schema::create('playback_grants', function (Blueprint $table) {
            $table->id();
            // The uuid IS the token in the URL.
            $table->uuid('uuid')->unique();
            // Context, not scope: a grant is issued and consumed without a
            // workspace being current, so no global scope may touch it.
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('media_asset_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            // The binding that makes a copied link useless elsewhere: when this
            // session ends, every grant it minted dies with it.
            $table->unsignedBigInteger('auth_session_id')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedSmallInteger('renewed_count')->default(0);
            // Audit only, never enforced: a phone changes IP mid-stream, and
            // enforcing it would cut off honest viewers on the move.
            $table->string('issued_ip_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'media_asset_id', 'expires_at']);
        });

        $this->migrateLessonMedia();
    }

    /**
     * Move whatever is in lessons.media into a documented asset.
     *
     * Malformed rows become `failed` with a reason rather than being dropped: the
     * teacher can see what happened and re-upload, whereas a silently discarded
     * row is a lesson that lost its video with no trace of why.
     */
    private function migrateLessonMedia(): void
    {
        if (! Schema::hasColumn('lessons', 'media')) {
            return;
        }

        $now = now();

        DB::table('lessons')
            ->select(['id', 'workspace_id', 'media'])
            ->whereNotNull('media')
            ->orderBy('id')
            ->chunk(200, function ($lessons) use ($now): void {
                foreach ($lessons as $lesson) {
                    $decoded = is_string($lesson->media) ? json_decode($lesson->media, true) : $lesson->media;

                    [$path, $duration] = match (true) {
                        is_string($decoded) && $decoded !== '' => [$decoded, null],
                        is_array($decoded) && isset($decoded['url']) => [
                            (string) $decoded['url'],
                            isset($decoded['duration']) ? (int) $decoded['duration'] : null,
                        ],
                        is_array($decoded) && isset($decoded['path']) => [
                            (string) $decoded['path'],
                            isset($decoded['duration']) ? (int) $decoded['duration'] : null,
                        ],
                        default => [null, null],
                    };

                    DB::table('media_assets')->insert([
                        'uuid' => (string) Str::orderedUuid(),
                        'workspace_id' => $lesson->workspace_id,
                        'owner_type' => 'App\Modules\Courses\Models\Lesson',
                        'owner_id' => $lesson->id,
                        'provider' => 'local',
                        'provider_asset_id' => $path,
                        'status' => $path === null ? 'failed' : 'ready',
                        'original_filename' => $path === null ? 'unknown' : basename($path),
                        'duration_seconds' => $duration,
                        'failure_reason' => $path === null
                            ? 'تعذّر قراءة مرجع الفيديو القديم — أعِد رفع الملف.'
                            : null,
                        'ready_at' => $path === null ? null : $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_grants');
        Schema::dropIfExists('media_captions');
        Schema::dropIfExists('media_assets');
    }
};
