<?php

namespace App\Support;

use App\Models\NewsletterConsent;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NewsletterSubscriptions
{
    public function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'));
    }

    /** Returns no existence information to the caller. */
    public function request(array $data, ?User $actor = null): void
    {
        $data['email'] = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $values = Validator::make($data, [
            'email' => ['required', 'email', 'max:255'], 'locale' => ['required', Rule::in(array_keys(config('news.locales')))],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])], 'consent' => ['accepted'],
            'website' => ['nullable', 'size:0'], 'confirmed_at' => ['prohibited'], 'user_id' => ['prohibited'], 'status' => ['prohibited'],
        ], array_fill_keys(['required', 'email', 'max', 'in', 'accepted', 'size', 'prohibited'], __('engagement.invalid')))->validate();
        $hash = $this->emailHash($values['email']);
        $lock = app(EngagementSettings::class)->cache()->lock('engagement:subscribe:'.$hash, 15);
        if (! $lock->get()) {
            return;
        }
        try {
            DB::transaction(function () use ($values, $hash, $actor): void {
                $subscriber = NewsletterSubscriber::where('email_hash', $hash)->lockForUpdate()->first();
                if ($subscriber?->last_requested_at?->greaterThan(now()->subMinutes(config('engagement.confirmation_cooldown_minutes')))) {
                    return;
                }
                $token = Str::random(64);
                if (! $subscriber) {
                    $secret = Str::random(64);
                    $subscriber = new NewsletterSubscriber;
                    $subscriber->forceFill(['public_id' => Str::uuid()->toString(), 'email' => $values['email'], 'email_hash' => $hash,
                        'locale' => $values['locale'], 'frequency' => $values['frequency'], 'consent_at' => now(),
                        'consent_version' => config('engagement.consent_version'), 'unsubscribe_secret' => $secret,
                        'unsubscribe_token_hash' => hash('sha256', $secret)]);
                }
                $owner = $actor?->fresh();
                $subscriber->forceFill(['pending_locale' => $values['locale'], 'pending_frequency' => $values['frequency'],
                    'pending_user_id' => $owner?->hasVerifiedEmail() && $this->emailHash($owner->email) === $hash ? $owner->id : null,
                    'confirmation_generation' => ($subscriber->confirmation_generation ?? 0) + 1,
                    'confirmation_token_hash' => hash('sha256', $token),
                    'confirmation_expires_at' => now()->addHours(config('engagement.confirmation_hours')),
                    'last_requested_at' => now()])->save();
                $this->audit($subscriber, 'requested', $values['locale'], $values['frequency'], $owner?->id);
                $url = app(PublicUrl::class)->base().URL::temporarySignedRoute('newsletter.confirm',
                    $subscriber->confirmation_expires_at, ['locale' => $values['locale'], 'subscriber' => $subscriber->public_id, 'token' => $token], absolute: false);
                app(EngagementDeliveries::class)->confirmation($subscriber, $url);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    public function validConfirmation(NewsletterSubscriber $subscriber, string $token): bool
    {
        return $subscriber->confirmation_token_hash !== null && strlen($token) === 64
            && hash_equals($subscriber->confirmation_token_hash, hash('sha256', $token))
            && $subscriber->confirmation_expires_at?->isFuture();
    }

    public function confirm(string $publicId, string $token): bool
    {
        return DB::transaction(function () use ($publicId, $token): bool {
            $subscriber = NewsletterSubscriber::where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if (! $this->validConfirmation($subscriber, $token)) {
                return false;
            }
            if ($subscriber->pending_locale === null && $subscriber->confirmed_at && ! $subscriber->unsubscribed_at) {
                return true;
            }
            $owner = User::find($subscriber->pending_user_id);
            $secret = Str::random(64);
            $subscriber->forceFill(['locale' => $subscriber->pending_locale, 'frequency' => $subscriber->pending_frequency,
                'confirmed_at' => now(), 'consent_at' => now(), 'consent_version' => config('engagement.consent_version'),
                'unsubscribed_at' => null, 'version' => $subscriber->version + 1,
                'user_id' => $owner?->hasVerifiedEmail() && $this->emailHash($owner->email) === $subscriber->email_hash ? $owner->id : null,
                'unsubscribe_secret' => $secret, 'unsubscribe_token_hash' => hash('sha256', $secret),
                'pending_locale' => null, 'pending_frequency' => null, 'pending_user_id' => null])->save();
            $this->audit($subscriber, 'confirmed', $subscriber->locale, $subscriber->frequency, $owner?->id);

            return true;
        }, 3);
    }

    public function unsubscribeUrl(NewsletterSubscriber $subscriber): string
    {
        return app(PublicUrl::class)->base().URL::signedRoute('newsletter.unsubscribe',
            ['locale' => $subscriber->locale, 'subscriber' => $subscriber->public_id, 'token' => $subscriber->unsubscribe_secret], absolute: false);
    }

    public function validUnsubscribe(NewsletterSubscriber $subscriber, string $token): bool
    {
        return strlen($token) === 64 && hash_equals($subscriber->unsubscribe_token_hash, hash('sha256', $token));
    }

    public function unsubscribe(NewsletterSubscriber $subscriber, ?User $actor = null): void
    {
        DB::transaction(function () use ($subscriber, $actor): void {
            $record = NewsletterSubscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            if ($record->unsubscribed_at) {
                return;
            }
            $record->forceFill(['unsubscribed_at' => now(), 'confirmation_token_hash' => null,
                'confirmation_expires_at' => null, 'pending_locale' => null, 'pending_frequency' => null, 'pending_user_id' => null,
                'version' => $record->version + 1])->save();
            $this->audit($record, 'unsubscribed', $record->locale, $record->frequency, $actor?->id);
        }, 3);
    }

    public function owned(User $actor): ?NewsletterSubscriber
    {
        $actor = $actor->fresh();
        if (! $actor?->hasVerifiedEmail()) {
            return null;
        }

        return NewsletterSubscriber::where('email_hash', $this->emailHash($actor->email))->first();
    }

    public function preferences(User $actor, array $data): void
    {
        $subscriber = $this->owned($actor);
        abort_unless($subscriber && $subscriber->confirmed_at && ! $subscriber->unsubscribed_at, 403);
        $values = Validator::make($data, ['locale' => ['required', Rule::in(array_keys(config('news.locales')))],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])], 'user_id' => ['prohibited'], 'email' => ['prohibited']],
            array_fill_keys(['required', 'in', 'prohibited'], __('engagement.invalid')))->validate();
        DB::transaction(function () use ($subscriber, $actor, $values): void {
            $record = NewsletterSubscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            abort_unless($record->confirmed_at && ! $record->unsubscribed_at && $actor->fresh()?->hasVerifiedEmail()
                && $this->emailHash($actor->fresh()->email) === $record->email_hash, 403);
            $record->forceFill($values + ['user_id' => $actor->id, 'version' => $record->version + 1])->save();
            $this->audit($record, 'preferences_updated', $record->locale, $record->frequency, $actor->id);
        });
    }

    public function removeAccount(User $actor): void
    {
        NewsletterSubscriber::where(function ($query) use ($actor): void {
            $query->where('user_id', $actor->id);
            if ($actor->hasVerifiedEmail()) {
                $query->orWhere('email_hash', $this->emailHash($actor->email));
            }
        })->get()->each(fn (NewsletterSubscriber $subscriber) => $this->unsubscribe($subscriber, $actor));
    }

    private function audit(NewsletterSubscriber $subscriber, string $action, string $locale, string $frequency, ?int $actorId): void
    {
        $record = new NewsletterConsent;
        $record->forceFill(['newsletter_subscriber_id' => $subscriber->id, 'email_hash' => $subscriber->email_hash, 'action' => $action,
            'locale' => $locale, 'frequency' => $frequency, 'consent_version' => config('engagement.consent_version'), 'actor_id' => $actorId])->save();
    }
}
