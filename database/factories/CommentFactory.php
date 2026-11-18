<?php

namespace Database\Factories;

use App\CommentStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'article_id' => Article::factory()->published()->has(ArticleTranslation::factory()->state(['locale' => 'en']), 'translations'),
            'user_id' => User::factory(), 'parent_id' => null, 'locale' => 'en',
            'body' => fake()->paragraph(), 'fingerprint' => hash('sha256', Str::uuid()),
            'status' => CommentStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => CommentStatus::Approved]);
    }
}
