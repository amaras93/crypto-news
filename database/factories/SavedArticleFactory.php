<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\SavedArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedArticle> */
class SavedArticleFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'article_id' => Article::factory()->published(), 'locale' => 'en'];
    }
}
