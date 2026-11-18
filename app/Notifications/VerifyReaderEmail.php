<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\PublicUrl;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class VerifyReaderEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('community.verify_subject'))
            ->line(__('community.verify_mail'))
            ->action(__('community.verify_email'), $this->verificationUrl($notifiable))
            ->line(__('community.ignore_mail'));
    }

    protected function verificationUrl(mixed $notifiable): string
    {
        return app(PublicUrl::class)->base().URL::temporarySignedRoute(
            'verification.verify', now()->addMinutes(60),
            ['locale' => $notifiable->preferredLocale(), 'id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
            absolute: false,
        );
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        return ! $notifiable->hasVerifiedEmail();
    }
}
