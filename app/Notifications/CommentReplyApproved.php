<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;
use App\Support\CommunityComments;
use App\Support\PublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentReplyApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $commentId)
    {
        $this->afterCommit();
    }

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        $comment = Comment::query()->find($this->commentId);

        return $notifiable->hasVerifiedEmail() && $notifiable->reply_notifications && ! $notifiable->community_suspended_at
            && $comment !== null && $comment->parent?->user_id === $notifiable->id && ! $comment->parent->trashed()
            && app(CommunityComments::class)->isPublic($comment);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $comment = Comment::query()->findOrFail($this->commentId);
        $translation = $comment->article->translation($comment->locale);
        $url = app(PublicUrl::class)->article($translation).'#comments';

        return (new MailMessage)->subject(__('community.reply_subject'))
            ->line(__('community.reply_mail'))->action(__('community.read_discussion'), $url)
            ->line(__('community.reply_settings'));
    }
}
