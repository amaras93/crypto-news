<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class EngagementMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $messageData, public string $deliveryId) {}

    public function build(): static
    {
        $this->withSymfonyMessage(function (Email $message): void {
            $message->getHeaders()->addTextHeader('X-CryptoNews-Delivery-ID', $this->deliveryId);
        });
        $subject = match ($this->messageData['kind']) {
            'confirmation' => __('engagement.confirm_subject'),
            'price_alert' => __('engagement.alert_subject'),
            default => __('engagement.digest_subject_'.$this->messageData['frequency']),
        };

        return $this->subject($subject)->view('emails.engagement')->text('emails.engagement-text')
            ->with(['messageData' => $this->messageData]);
    }
}
