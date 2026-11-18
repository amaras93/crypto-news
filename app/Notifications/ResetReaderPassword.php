<?php

namespace App\Notifications;

use App\Support\PublicUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetReaderPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = app(PublicUrl::class)->route('password.reset', [
            'locale' => $notifiable->preferredLocale(), 'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)->subject(__('community.reset_password'))
            ->line(__('community.reset_mail', ['minutes' => config('auth.passwords.users.expire')]))
            ->action(__('community.reset_password'), $url)->line(__('community.ignore_mail'));
    }
}
