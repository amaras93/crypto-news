<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\EditorialEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EditorialEvent> */
class EditorialEventFactory extends Factory
{
    public function definition(): array
    {
        return ['article_id' => Article::factory(), 'user_id' => User::factory()->admin(), 'action' => 'content_saved'];
    }
}
