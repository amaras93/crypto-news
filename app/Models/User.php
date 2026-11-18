<?php

namespace App\Models;

use App\Notifications\ResetReaderPassword;
use App\Notifications\VerifyReaderEmail;
use App\Support\NewsletterSubscriptions;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference, MustVerifyEmail
{
    protected $attributes = ['price_alert_in_app' => true, 'price_alert_email' => false];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            app(NewsletterSubscriptions::class)->removeAccount($user);
            $user->notifications()->delete();
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->isEditorial();
    }

    public function isEditorial(): bool
    {
        return $this->is_admin || $this->is_editor;
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function commentLikes(): HasMany
    {
        return $this->hasMany(CommentLike::class);
    }

    public function preferredLocale(): string
    {
        return array_key_exists((string) $this->preferred_locale, config('news.locales')) ? $this->preferred_locale : 'en';
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path && preg_match('#^avatars/[a-f0-9-]{36}\\.(jpg|png|webp)$#D', $this->avatar_path)
            ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyReaderEmail);
    }

    public function sendPasswordResetNotification(mixed $token): void
    {
        $this->notify(new ResetReaderPassword((string) $token));
    }

    public function reviewedArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'reviewer_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_editor' => 'boolean',
            'reply_notifications' => 'boolean',
            'price_alert_in_app' => 'boolean',
            'price_alert_email' => 'boolean',
            'community_suspended_at' => 'immutable_datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
