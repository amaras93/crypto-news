<?php

namespace App\Support;

use App\Jobs\DeliverEngagementMessage;
use App\Mail\EngagementMessage;
use App\Models\EngagementDelivery;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Models\PriceAlert;
use App\Models\PriceAlertEvent;
use App\Models\User;
use App\Notifications\PriceThresholdCrossed;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class EngagementDeliveries
{
    public function __construct(private EngagementSettings $settings, private NewsletterSubscriptions $subscriptions, private NewsDigests $digests) {}

    public function confirmation(NewsletterSubscriber $subscriber, string $url): EngagementDelivery
    {
        return $this->enqueue('confirmation|'.$subscriber->id.'|'.$subscriber->confirmation_generation, [
            'kind' => 'confirmation', 'channel' => 'mail', 'locale' => $subscriber->pending_locale,
            'newsletter_subscriber_id' => $subscriber->id, 'confirmation_generation' => $subscriber->confirmation_generation,
            'recipient_hash' => $subscriber->email_hash, 'payload' => ['confirmation_url' => $url],
        ]);
    }

    public function digest(NewsletterSubscriber $subscriber, NewsletterCampaign $campaign): EngagementDelivery
    {
        return $this->enqueue('digest|'.$campaign->id.'|'.$subscriber->id, [
            'kind' => 'digest', 'channel' => 'mail', 'locale' => $campaign->locale,
            'newsletter_subscriber_id' => $subscriber->id, 'newsletter_campaign_id' => $campaign->id,
            'subscriber_version' => $subscriber->version, 'recipient_hash' => $subscriber->email_hash,
        ]);
    }

    public function forPriceEvent(PriceAlertEvent $event, PriceAlert $alert): void
    {
        $user = User::find($event->user_id);
        if (! $user) {
            return;
        }
        foreach (['database' => $alert->in_app && $user->price_alert_in_app, 'mail' => $alert->email && $user->price_alert_email && $user->hasVerifiedEmail()] as $channel => $enabled) {
            if ($enabled) {
                $this->enqueue('price|'.$event->public_id.'|'.$channel, ['kind' => 'price_alert', 'channel' => $channel,
                    'locale' => $user->preferredLocale(), 'user_id' => $user->id, 'price_alert_event_id' => $event->id,
                    'recipient_hash' => $channel === 'mail' ? $this->subscriptions->emailHash($user->email) : null]);
            }
        }
    }

    public function manualTest(User $actor, NewsletterCampaign $campaign): EngagementDelivery
    {
        abort_unless($actor->fresh()?->is_admin && $actor->fresh()->hasVerifiedEmail(), 403);
        CommunityLimits::hit('engagement-admin-test', (string) $actor->id, 5, 3600);

        return $this->enqueue('test|'.$campaign->id.'|'.$actor->id.'|'.Str::uuid(), ['kind' => 'admin_test', 'channel' => 'mail',
            'locale' => $campaign->locale, 'user_id' => $actor->id, 'newsletter_campaign_id' => $campaign->id,
            'recipient_hash' => $this->subscriptions->emailHash($actor->email)]);
    }

    private function enqueue(string $key, array $data): EngagementDelivery
    {
        $key = hash('sha256', $key);
        $record = EngagementDelivery::where('unique_key', $key)->first();
        if ($record) {
            return $record;
        }
        $record = new EngagementDelivery;
        $record->forceFill($data + ['id' => Str::uuid()->toString(), 'unique_key' => $key, 'status' => 'queued', 'available_at' => now()])->save();
        $this->dispatch($record);

        return $record;
    }

    public function dispatch(EngagementDelivery $delivery): void
    {
        $claimed = DB::transaction(function () use ($delivery): bool {
            $record = EngagementDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($record->status !== 'queued' || $record->available_at?->isFuture() || $record->queued_at) {
                return false;
            }
            $record->forceFill(['queued_at' => now()])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }
        DB::afterCommit(function () use ($delivery): void {
            try {
                DeliverEngagementMessage::dispatch($delivery->id);
            } catch (Throwable) {
                $delivery->refresh();
                if ($delivery->status !== 'queued' || $delivery->attempts !== 0) {
                    return;
                }
                $delivery->forceFill(['status' => $delivery->dispatch_failures >= 2 ? 'failed' : 'queued', 'queued_at' => null,
                    'dispatch_failures' => $delivery->dispatch_failures + 1, 'error_code' => 'queue_unavailable', 'available_at' => now()->addMinutes(2)])->save();
            }
        });
    }

    public function process(string $id): void
    {
        $lock = $this->settings->cache()->lock('engagement:delivery:'.$id, 90);
        if (! $lock->get()) {
            return;
        }
        try {
            $record = EngagementDelivery::find($id);
            if (! $record || $record->status !== 'queued' || $record->available_at?->isFuture()) {
                return;
            }
            if (! $this->eligible($record)) {
                $this->finish($record, 'skipped', 'preferences_or_content_changed');

                return;
            }
            if ($record->channel === 'database') {
                DB::transaction(function () use ($id): void {
                    $record = EngagementDelivery::query()->lockForUpdate()->findOrFail($id);
                    if ($record->status !== 'queued' || ! $this->eligible($record)) {
                        return;
                    }
                    $user = $record->user;
                    if (! $user->notifications()->whereKey($record->id)->exists()) {
                        $notice = new PriceThresholdCrossed($record->price_alert_event_id);
                        $notice->id = $record->id;
                        $user->notifyNow($notice, ['database']);
                    }
                    $record->forceFill(['status' => 'stored', 'attempts' => $record->attempts + 1, 'completed_at' => now()])->save();
                });

                return;
            }
            $mailer = (string) config('mail.default');
            if (! in_array($mailer, ['log', 'array', 'smtp'], true) || ($mailer === 'smtp' && ! $this->settings->enabled('real_email_enabled'))) {
                $this->finish($record, 'skipped', 'real_email_disabled_or_unverified_transport');

                return;
            }
            if (! $this->takeRateSlot()) {
                $record->forceFill(['available_at' => now()->addMinute(), 'queued_at' => null, 'error_code' => 'rate_limited'])->save();

                return;
            }
            $prepared = DB::transaction(function () use ($id, $mailer): bool {
                $record = EngagementDelivery::query()->lockForUpdate()->findOrFail($id);
                if ($record->status !== 'queued' || ! $this->eligible($record)) {
                    return false;
                }
                $record->forceFill(['status' => 'sending', 'sending_at' => now(), 'attempts' => $record->attempts + 1,
                    'transport' => $mailer, 'locale' => $record->kind === 'price_alert' ? $record->user->preferredLocale() : $record->locale])->save();

                return true;
            });
            if (! $prepared) {
                return;
            }
            $record->refresh();
            try {
                if (! $this->eligible($record)) {
                    $this->finish($record, 'skipped', 'preferences_or_content_changed');

                    return;
                }
                $data = $this->messageData($record);
                if ($data === null) {
                    $this->finish($record, 'skipped', 'no_published_content');

                    return;
                }
                $recipient = in_array($record->kind, ['price_alert', 'admin_test'], true) ? $record->user->email : $record->subscriber->email;
                $message = new EngagementMessage($data, $record->id);
                $message->to($recipient)->locale($record->locale);
                $sent = Mail::mailer($mailer)->send($message);
                if (in_array($mailer, ['log', 'array'], true)) {
                    $this->finish($record, 'local', 'local_transport_only');
                } elseif ($sent instanceof SentMessage && filled($sent->getMessageId())) {
                    $record->forceFill(['provider_message_id' => substr($sent->getMessageId(), 0, 255)])->save();
                    $this->finish($record, 'accepted');
                } else {
                    $this->finish($record, 'uncertain', 'no_transport_confirmation');
                }
            } catch (Throwable) {
                $this->finish($record, 'uncertain', 'mail_outcome_unknown');
            }
        } finally {
            $lock->release();
        }
    }

    private function eligible(EngagementDelivery $delivery): bool
    {
        if ($delivery->kind === 'price_alert') {
            $event = $delivery->alertEvent;
            $alert = $event?->alert;
            $user = $delivery->user;
            if (config('markets.mode') !== 'live' || ! $this->settings->enabled('price_alerts_enabled') || ! $event || ! $alert
                || ! $user || ! $alert->enabled || $alert->version !== $event->alert_version || $event->user_id !== $user->id
                || $event->provider === 'demo' || $alert->user_id !== $event->user_id) {
                return false;
            }
            $markets = app(MarketDataService::class);
            $current = collect($markets->snapshot()['quotes'])->firstWhere('asset', $alert->asset);
            if (! app(PriceAlerts::class)->isCurrentQuote($alert, $current) || $event->observed_timestamp > now()->timestamp
                || now()->timestamp - $event->observed_timestamp > $markets->interval(config('markets.assets.'.$alert->asset.'.group')) + 60) {
                return false;
            }

            return $delivery->channel === 'database' ? $user->price_alert_in_app && $alert->in_app
                : $user->price_alert_email && $alert->email && $user->hasVerifiedEmail() && $this->subscriptions->emailHash($user->email) === $delivery->recipient_hash;
        }
        if ($delivery->kind === 'admin_test') {
            return (bool) $delivery->user?->is_admin && $delivery->user->hasVerifiedEmail()
                && $this->subscriptions->emailHash($delivery->user->email) === $delivery->recipient_hash && $delivery->campaign !== null;
        }
        $subscriber = $delivery->subscriber;
        if (! $subscriber || $subscriber->email_hash !== $delivery->recipient_hash) {
            return false;
        }
        if ($delivery->kind === 'confirmation') {
            return $subscriber->confirmation_generation === $delivery->confirmation_generation && $subscriber->pending_locale !== null
                && $subscriber->confirmation_expires_at?->isFuture() && filled($subscriber->confirmation_token_hash);
        }
        $campaign = $delivery->campaign;

        return $delivery->kind === 'digest' && $this->settings->enabled('newsletters_enabled') && $subscriber->confirmed_at && ! $subscriber->unsubscribed_at
            && $subscriber->version === $delivery->subscriber_version && $subscriber->locale === $delivery->locale
            && $campaign && $campaign->locale === $subscriber->locale && $campaign->frequency === $subscriber->frequency
            && in_array($campaign->status, ['approved', 'queuing', 'audience_queued'], true) && $campaign->approved_at && $campaign->approver?->is_admin;
    }

    public function messageData(EngagementDelivery $delivery): ?array
    {
        if ($delivery->kind === 'confirmation') {
            return ['kind' => 'confirmation', 'confirmation_url' => $delivery->payload['confirmation_url']];
        }
        if ($delivery->kind === 'price_alert') {
            return ['kind' => 'price_alert', 'event' => $delivery->alertEvent,
                'account_url' => route('account.alerts', ['locale' => $delivery->locale])];
        }
        $articles = $this->digests->articles($delivery->campaign);
        if ($articles->isEmpty()) {
            return null;
        }

        return ['kind' => 'digest', 'articles' => $articles, 'frequency' => $delivery->campaign->frequency,
            'unsubscribe_url' => $delivery->kind === 'admin_test' ? null : $this->subscriptions->unsubscribeUrl($delivery->subscriber),
            'is_preview' => $delivery->kind === 'admin_test'];
    }

    private function takeRateSlot(): bool
    {
        $lock = $this->settings->cache()->lock('engagement:mail-rate', 10);
        if (! $lock->get()) {
            return false;
        }
        try {
            $key = 'engagement:mail-rate:'.now()->utc()->format('YmdHi');
            $count = (int) $this->settings->cache()->get($key, 0);
            if ($count >= $this->settings->emailRate()) {
                return false;
            }
            $this->settings->cache()->put($key, $count + 1, 120);

            return true;
        } finally {
            $lock->release();
        }
    }

    public function finish(EngagementDelivery $delivery, string $status, ?string $code = null): void
    {
        $payload = $delivery->payload ?? [];
        unset($payload['confirmation_url']);
        $delivery->forceFill(['status' => $status, 'error_code' => $code, 'completed_at' => now(), 'payload' => $payload ?: null])->save();
    }

    public function recover(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $delivery = EngagementDelivery::query()->lockForUpdate()->find($id);
            if ($delivery?->status === 'sending') {
                $this->finish($delivery, 'uncertain', 'worker_outcome_unknown');
            } elseif ($delivery?->status === 'queued') {
                $delivery->forceFill(['status' => 'failed', 'error_code' => 'worker_failed_before_send', 'completed_at' => now()])->save();
            }
        });
    }

    public function retryUnsent(User $actor, EngagementDelivery $delivery): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        DB::transaction(function () use ($delivery): void {
            $record = EngagementDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $orphaned = $record->status === 'queued' && $record->queued_at?->lessThan(now()->subHour());
            abort_unless(($record->status === 'failed' || $orphaned) && $record->attempts === 0 && $record->dispatch_failures < 6, 422);
            $record->forceFill(['status' => 'queued', 'queued_at' => null, 'dispatch_failures' => $record->dispatch_failures + 1,
                'available_at' => now(), 'error_code' => null, 'completed_at' => null])->save();
            $this->dispatch($record);
        });
    }

    public function resolveUnknown(User $actor, EngagementDelivery $delivery, bool $accepted, ?string $messageId, string $notes): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        Validator::make(['notes' => $notes, 'message_id' => $messageId], [
            'notes' => ['required', 'string', 'min:30', 'max:1000'],
            'message_id' => [$accepted ? 'required' : 'nullable', 'string', 'max:255', 'not_regex:/[\\r\\n]/'],
        ])->validate();
        DB::transaction(function () use ($actor, $delivery, $accepted, $messageId, $notes): void {
            $record = EngagementDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            abort_unless($record->status === 'uncertain' && $record->channel === 'mail', 422);
            abort_unless(! $accepted || $record->transport === 'smtp', 422);
            $record->forceFill(['provider_message_id' => $accepted ? $messageId : null,
                'payload' => ['operator_resolution' => ['by' => $actor->id, 'at' => now()->toIso8601String(), 'notes' => $notes]]])->save();
            $this->finish($record, $accepted ? 'accepted' : 'closed', $accepted ? 'operator_verified_transport_acceptance' : 'closed_without_resending');
        });
    }

    public function dispatchPending(): int
    {
        foreach (EngagementDelivery::where('status', 'sending')->where('sending_at', '<', now()->subMinutes(5))->limit(100)->get() as $delivery) {
            $this->recover($delivery->id);
        }
        $records = EngagementDelivery::where('status', 'queued')->whereNull('queued_at')->where('available_at', '<=', now())
            ->orderBy('available_at')->limit($this->settings->batchSize())->get();
        foreach ($records as $record) {
            $this->dispatch($record);
        }
        foreach (NewsletterCampaign::whereIn('status', ['approved', 'queuing'])->orderBy('id')->limit(8)->get() as $campaign) {
            $this->digests->dispatchAudience($campaign);
        }

        return $records->count();
    }
}
