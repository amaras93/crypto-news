<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentReportFactory extends Factory
{
    public function definition(): array
    {
        return ['comment_id' => Comment::factory()->approved(), 'user_id' => User::factory(), 'reason' => 'spam'];
    }
}
