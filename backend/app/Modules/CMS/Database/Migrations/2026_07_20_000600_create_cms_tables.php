<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('cms_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('cms_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug');
            $table->longText('body')->nullable();
            $table->string('excerpt')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->string('canonical_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('cms_article_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['article_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_article_tag');
        Schema::dropIfExists('cms_articles');
        Schema::dropIfExists('cms_tags');
        Schema::dropIfExists('cms_categories');
    }
};
