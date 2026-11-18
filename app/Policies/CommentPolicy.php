<?php

namespace App\Policies;

use App\CommentStatus;
use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, Comment $comment): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail() && ! $user->community_suspended_at;
    }

    public function update(User $user, Comment $comment): bool
    {
        return $this->create($user) && $comment->user_id === $user->id && ! $comment->trashed()
            && in_array($comment->status, [CommentStatus::Pending, CommentStatus::Approved], true)
            && $comment->created_at->greaterThanOrEqualTo(now()->subMinutes(config('community.edit_minutes')));
    }

    public function delete(User $user, Comment $comment): bool
    {
        return ! $comment->trashed() && $comment->user_id === $user->id;
    }

    public function moderate(User $user, Comment $comment): bool
    {
        return (bool) $user->is_admin;
    }

    public function moderateAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function restore(User $user, Comment $comment): bool
    {
        return $user->is_admin && $comment->trashed();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Comment $comment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
