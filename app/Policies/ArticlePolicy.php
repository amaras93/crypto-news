<?php

namespace App\Policies;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\User;

class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorial();
    }

    public function view(User $user, Article $article): bool
    {
        return $user->is_admin || ($user->is_editor && $article->author_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->isEditorial();
    }

    public function update(User $user, Article $article): bool
    {
        return ! $article->trashed() && ($user->is_admin || ($user->is_editor && $article->author_id === $user->id
            && in_array($article->status, [ArticleStatus::Draft, ArticleStatus::PendingReview], true)));
    }

    public function submit(User $user, Article $article): bool
    {
        return $this->update($user, $article);
    }

    public function publish(User $user, Article $article): bool
    {
        return $user->is_admin && ! $article->trashed();
    }

    public function preview(User $user, Article $article): bool
    {
        return ! $article->trashed() && $this->view($user, $article);
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->is_admin && ! $article->trashed();
    }

    public function deleteAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function restore(User $user, Article $article): bool
    {
        return $user->is_admin && $article->trashed();
    }

    public function restoreAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function forceDelete(User $user, Article $article): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Article $article): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
