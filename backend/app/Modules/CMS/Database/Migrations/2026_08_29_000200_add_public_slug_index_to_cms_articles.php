<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T026 — the two indexes the public blog needs, and neither of the
 * two the table already had could serve it.
 *
 * ⚠️ EVERY EXISTING INDEX ON THIS TABLE STARTS WITH `workspace_id`, AND A PUBLIC
 * READER HAS NO WORKSPACE. `WorkspaceScope::apply()` adds no condition when the
 * context is null, which it always is for a guest — so `/blog/{slug}` and the
 * sitemap arrive with `workspace_id` absent from the predicate, the leading
 * column of both indexes is unbound, and MySQL scans the whole table. On every
 * article opened and every sitemap built.
 *
 * ⚠️ AND `unique(workspace_id, slug)` MAKES `/blog/{slug}` AMBIGUOUS BY
 * DEFINITION. Two teachers may both publish «خطة-المراجعة» under it, and the
 * public route has nothing to tell them apart with — whichever row comes back
 * first is the article the reader gets, and which one that is can change between
 * two requests. The composite is dropped rather than kept beside the new one:
 * a global unique implies it, and two constraints answering one question is the
 * second spelling this repository keeps paying for.
 *
 * ⚠️ THE PRICE, WRITTEN DOWN: a soft-deleted article now holds its slug against
 * the WHOLE platform, not just its own workspace. A unique index does not know
 * about `deleted_at`. That is the correct trade — a public URL that silently
 * starts resolving to somebody else's article is worse than a slug a teacher
 * must vary — but it means `SaveArticle` has to answer «هذا الرابط مستخدم» from
 * the trashed set too, or a teacher gets a raw integrity error.
 *
 * Two closures, deliberately: a multi-alteration on SQLite is a table rebuild,
 * and every test in this repository runs on in-memory SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_articles', function (Blueprint $table): void {
            $table->dropUnique(['workspace_id', 'slug']);
        });

        Schema::table('cms_articles', function (Blueprint $table): void {
            $table->unique('slug');

            // `WHERE status = 'published' ORDER BY published_at DESC` — the blog
            // index and the sitemap, both of them guests. `status` leads because
            // it is the equality; a lone `published_at` index would still filter
            // by scanning.
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cms_articles', function (Blueprint $table): void {
            $table->dropIndex(['status', 'published_at']);
            $table->dropUnique(['slug']);
        });

        Schema::table('cms_articles', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'slug']);
        });
    }
};
