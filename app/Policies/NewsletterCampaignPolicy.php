<?php

namespace App\Policies;

use App\Models\NewsletterCampaign;
use App\Models\User;

class NewsletterCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, NewsletterCampaign $record): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, NewsletterCampaign $record): bool
    {
        return false;
    }

    public function delete(User $user, NewsletterCampaign $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
