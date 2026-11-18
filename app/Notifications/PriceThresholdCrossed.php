<?php

namespace App\Notifications;

use App\Models\PriceAlertEvent;
use App\Models\User;
use Illuminate\Notifications\Notification;

class PriceThresholdCrossed extends Notification
{
    public function __construct(public int $eventId) {}

    public function via(User $notifiable): array
    {
        return ['database'];
    }

    public function toArray(User $notifiable): array
    {
        $event = PriceAlertEvent::findOrFail($this->eventId);
        abort_unless($event->user_id === $notifiable->id, 403);

        return ['event' => $event->public_id, 'asset' => $event->asset, 'currency' => $event->currency, 'unit' => $event->unit,
            'direction' => $event->direction, 'target_price' => $event->target_price, 'observed_price' => $event->observed_price,
            'observed_timestamp' => $event->observed_timestamp];
    }
}
