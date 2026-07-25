<?php

declare(strict_types=1);

namespace Database\Factories\Modules\CMS;

use App\Models\User;
use App\Modules\CMS\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = fake()->sentence(5);

        return [
            'workspace_id' => 1,
            'uuid' => Str::uuid(),
            'title' => $title,
            'slug' => Str::slug($title),
            'body' => fake()->paragraphs(5, true),
            'excerpt' => fake()->sentence(),
            'status' => 'draft',
            'published_at' => null,
            'author_id' => User::factory(),
            'category_id' => null,
            'seo_title' => null,
            'seo_description' => null,
            'canonical_url' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'published_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ]);
    }
}
