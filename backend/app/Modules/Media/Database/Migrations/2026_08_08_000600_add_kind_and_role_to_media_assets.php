<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opens the upload pipeline to documents and audio, and to more than one file
 * per lesson.
 *
 * `pdf` and `file` have been declared lesson types since the first migration
 * with no way to upload either: RequestUploadTicket goes through the video
 * provider and CompleteMediaUpload matched one flat mime list whose rejection
 * message said "not a supported video". The storage mechanism never needed
 * replacing — MediaAsset is already polymorphic and the local provider already
 * streams by range request, which is exactly what serving a PDF needs. What was
 * missing is the classification these two columns carry.
 *
 * `role` exists because RequestUploadTicket deletes the existing asset before
 * creating the next one. That is right for the lesson's own video and wrong the
 * moment a worksheet is attached beside it: primary is one per owner,
 * attachments are many. Enforced in the Action rather than by a partial unique
 * index, which MySQL does not have.
 *
 * `is_downloadable` is the view-only switch, and it lives on the server because
 * hiding a button in the browser is not a restriction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('kind')->default('video')->after('provider_asset_id');
            $table->string('role')->default('primary')->after('kind');
            // Default false: a lecture note that leaks is worse than a worksheet
            // that has to be enabled. The safer value is the one you get by
            // forgetting to choose.
            $table->boolean('is_downloadable')->default(false)->after('role');

            $table->index(['owner_type', 'owner_id', 'role'], 'media_assets_owner_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex('media_assets_owner_role_index');
            $table->dropColumn(['kind', 'role', 'is_downloadable']);
        });
    }
};
