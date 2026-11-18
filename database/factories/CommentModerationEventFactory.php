<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentModerationEventFactory extends Factory
{
    public function definition(): array
    {
        return ['comment_id' => Comment::factory(), 'user_id' => User::factory()->admin(), 'action' => 'approve', 'previous_status' => 'pending', 'new_status' => 'approved'];
    }
}
