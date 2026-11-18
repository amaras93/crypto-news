<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
#[Hidden(['email', 'email_hash', 'unsubscribe_secret', 'unsubscribe_token_hash', 'confirmation_token_hash'])]
class NewsletterSubscriber extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'unsubscribe_secret' => 'encrypted', 'consent_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'unsubscribed_at' => 'immutable_datetime', 'confirmation_expires_at' => 'immutable_datetime', 'last_requested_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
